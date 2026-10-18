<?php

namespace App\Ingestion;

use App\Enums\FetchFailureKind;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Ingestion\Data\ExtractedItem;
use App\Ingestion\Health\SourceHealthTracker;
use App\Ingestion\Http\FetchRequest;
use App\Ingestion\Http\FetchResult;
use App\Ingestion\Http\HostBusy;
use App\Ingestion\Http\HttpFetcher;
use App\Ingestion\Ics\IcsExtractor;
use App\Ingestion\Pipeline\SourceItemRecorder;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One source run, end to end (03-05): fetch politely → validate → store the
 * raw body → extract → record each item → counters and source state.
 *
 * Source problems are recorded on the run, never thrown (Pitfall 10):
 * robots.txt refusal → blocked; 304 → not_modified; a fetch failure or a
 * BodyValidator rejection → failed with its FetchFailureKind (a bot wall is
 * content_invalid, SRC-06); a fatal extraction error → failed parse_error.
 * None of these writes items or drafts, but any body that arrived is stored
 * with RawBodyStore for inspection. HostBusy is the one exception that
 * escapes: FetchSourceJob releases itself on it.
 *
 * Each item is recorded in its own transaction; an item that throws counts
 * as skipped_parse_error and is logged by ids only, so one bad item never
 * fails the run. error_message is built from enum labels, status codes and
 * our own wording only, never response text, and is at most 500 characters.
 *
 * Preview runs (D-01) go through the same fetch, validation, extraction and
 * classification (SourceItemRecorder::verdict, shared with live runs) but
 * write nothing except the run itself: no source items, drafts, source
 * state or health. Their would-be outcome is stored in run.preview (≤ 200
 * rows) and their body in RawBodyStore. A live activation run ("Create
 * drafts") reuses the latest preview's body when that preview succeeded,
 * fetched the same URL and finished under ingest.preview_reuse_minutes ago
 * (D-24), so the site is not asked twice.
 *
 * Every finished live run is handed to SourceHealthTracker (health,
 * structure alert, is_complete) before missing detection (03-09).
 */
final class IngestRun
{
    /** source_runs.error_message length (characters). */
    public const int ERROR_MESSAGE_LIMIT = 500;

    /** Rows kept in a preview run's `preview` (D-01). */
    public const int PREVIEW_ROW_LIMIT = 200;

    /** source_runs.final_url length (characters). */
    private const int FINAL_URL_LIMIT = 2048;

    private const string LONDON = 'Europe/London';

    public function __construct(
        private readonly HttpFetcher $fetcher,
        private readonly RawBodyStore $raw,
        private readonly IcsExtractor $extractor,
        private readonly SourceItemRecorder $recorder,
        private readonly SourceHealthTracker $health,
    ) {}

    /**
     * @throws HostBusy when the host's next slot is too far away; nothing on
     *                  the run has been written yet
     */
    public function execute(SourceRun $run): void
    {
        $source = $run->source()->firstOrFail();
        $result = $this->reusablePreviewBody($run, $source)
            ?? $this->fetcher->fetch($this->request($run, $source));

        $run->forceFill([
            'http_status' => $result->httpStatus,
            'final_url' => mb_substr($result->finalUrl, 0, self::FINAL_URL_LIMIT),
            'bytes' => $result->bytes,
            'duration_ms' => $result->durationMs,
        ]);

        if ($result->body !== null) {
            $run->forceFill([
                'raw_path' => $this->raw->put($source->id, $run->id, $result->body),
                'raw_sha256' => hash('sha256', $result->body),
            ]);
        }

        match (true) {
            $result->isBlocked() => $this->finish($run, $source, RunStatus::Blocked, null, $result->message),
            $result->isNotModified() => $this->finish($run, $source, RunStatus::NotModified, null, null, $result),
            $result->isFailed() => $this->finish($run, $source, RunStatus::Failed, $result->failureKind ?? FetchFailureKind::Network, $result->message),
            default => $this->process($run, $source, $result),
        };
    }

    /**
     * An error message safe for source_runs.error_message: at most 500
     * characters, cut on a character boundary (never mid UTF-8 sequence).
     */
    public static function errorMessage(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        return mb_substr(trim($message), 0, self::ERROR_MESSAGE_LIMIT, 'UTF-8');
    }

    /**
     * The fetch for this run. The conditional validators are sent only on a
     * live run whose previous live run succeeded (or was not modified), so a
     * run that failed half way is never answered with a 304. A preview never
     * sends them: it needs the body.
     */
    private function request(SourceRun $run, Source $source): FetchRequest
    {
        if ($run->mode !== RunMode::Live) {
            return new FetchRequest(url: $source->url, etag: null, lastModified: null, startedAt: $run->started_at);
        }

        $previous = SourceRun::query()
            ->where('source_id', $source->id)
            ->where('mode', RunMode::Live)
            ->where('id', '<', $run->id)
            ->whereNotIn('status', [RunStatus::Queued, RunStatus::Running])
            ->orderByDesc('id')
            ->first();

        $conditional = $previous !== null && in_array($previous->status, [RunStatus::Succeeded, RunStatus::NotModified], true);

        return new FetchRequest(
            url: $source->url,
            etag: $conditional ? $source->etag : null,
            lastModified: $conditional ? $source->last_modified : null,
            startedAt: $run->started_at,
        );
    }

    private function process(SourceRun $run, Source $source, FetchResult $result): void
    {
        $nowLondon = CarbonImmutable::now(self::LONDON);
        $extraction = $this->extractor->extract((string) $result->body, $source, $nowLondon);

        if ($extraction->failed()) {
            $message = FetchFailureKind::ParseError->getLabel().': '.$extraction->fatalError;
            $this->finish($run, $source, RunStatus::Failed, FetchFailureKind::ParseError, $message);

            return;
        }

        if ($run->mode === RunMode::Preview) {
            $this->preview($run, $source, $extraction->items, $nowLondon, $result);

            return;
        }

        $counters = array_fill_keys(SourceRun::COUNTERS, 0);
        $observedUntil = null;

        foreach ($extraction->items as $index => $item) {
            $counters['items_found']++;
            $observedUntil = $this->laterDate($observedUntil, $item);

            try {
                $bucket = $this->recorder->record($run, $source, $item, $nowLondon);
            } catch (Throwable $e) {
                // Ids only: never feed text, URLs or SQL bindings (T-01-22).
                Log::error('ingest: item failed', [
                    'run_id' => $run->id,
                    'source_id' => $source->id,
                    'item_index' => $index,
                    'exception' => $e::class,
                ]);
                $bucket = SourceItemRecorder::SKIPPED_PARSE_ERROR;
            }

            $counters[SourceItemRecorder::COUNTER[$bucket]]++;

            if ($bucket === SourceItemRecorder::NEW) {
                $counters['drafts_created']++;
            }
        }

        $run->forceFill([
            ...$counters,
            'observed_from' => $nowLondon->toDateString(),
            'observed_until' => $observedUntil,
        ]);

        $this->finish($run, $source, RunStatus::Succeeded, null, null, $result);
    }

    /**
     * D-01: classify every item exactly as a live run would (verdict()),
     * write nothing but the run: counters as "would be", drafts_created 0,
     * and run.preview = {url, would_create, skipped, rows (≤ 200)}.
     *
     * @param  list<ExtractedItem>  $items
     */
    private function preview(SourceRun $run, Source $source, array $items, CarbonImmutable $nowLondon, FetchResult $result): void
    {
        $counters = array_fill_keys(SourceRun::COUNTERS, 0);
        $observedUntil = null;
        $rows = [];

        foreach ($items as $index => $item) {
            $counters['items_found']++;
            $observedUntil = $this->laterDate($observedUntil, $item);

            try {
                $bucket = $this->recorder->verdict($source, $item, $nowLondon);
            } catch (Throwable $e) {
                Log::error('ingest: preview item failed', [
                    'run_id' => $run->id,
                    'source_id' => $source->id,
                    'item_index' => $index,
                    'exception' => $e::class,
                ]);
                $bucket = SourceItemRecorder::SKIPPED_PARSE_ERROR;
            }

            $counters[SourceItemRecorder::COUNTER[$bucket]]++;

            if (count($rows) < self::PREVIEW_ROW_LIMIT) {
                $rows[] = $this->previewRow($item, $bucket);
            }
        }

        $run->forceFill([
            ...$counters,
            'observed_from' => $nowLondon->toDateString(),
            'observed_until' => $observedUntil,
            'preview' => [
                // The address fetched: D-24 reuses the body only for the same URL.
                'url' => $source->url,
                'would_create' => $counters['items_new'],
                'skipped' => [
                    'past' => $counters['skipped_past'],
                    'far_future' => $counters['skipped_far_future'],
                    'rule' => $counters['skipped_rule'],
                    'parse_error' => $counters['skipped_parse_error'],
                ],
                'rows' => $rows,
            ],
        ]);

        $this->finish($run, $source, RunStatus::Succeeded, null, null, $result);
    }

    /**
     * One preview row: title (≤ 255 characters), London start ("Y-m-d" or
     * "Y-m-d H:i", the next instance for a series), verdict new / changed /
     * unchanged / skipped, and the skip reason past / far_future / rule /
     * parse_error (null otherwise).
     *
     * @return array{title: string|null, starts: string|null, verdict: string, reason: string|null}
     */
    private function previewRow(ExtractedItem $item, string $bucket): array
    {
        $facts = $item->facts;
        $starts = null;

        if ($facts !== null) {
            $next = $facts->nextInstanceStartUtc?->setTimezone(self::LONDON);
            $date = $next?->toDateString() ?? $facts->startDate;
            $time = $next?->format('H:i') ?? ($facts->startTime !== null ? substr($facts->startTime, 0, 5) : null);
            $starts = $facts->allDay || $time === null ? $date : $date.' '.$time;
        }

        $skipped = str_starts_with($bucket, 'skipped_');

        return [
            'title' => $facts !== null ? mb_substr($facts->title, 0, 255, 'UTF-8') : null,
            'starts' => $starts,
            'verdict' => $skipped ? 'skipped' : $bucket,
            'reason' => $skipped ? substr($bucket, strlen('skipped_')) : null,
        ];
    }

    /**
     * D-24: for a live activation run, the latest preview's body as a fetch
     * result, when that preview succeeded, fetched the source's current URL,
     * finished under ingest.preview_reuse_minutes ago and its stored body
     * still matches its fingerprint. Null means fetch politely as usual.
     */
    private function reusablePreviewBody(SourceRun $run, Source $source): ?FetchResult
    {
        if ($run->mode !== RunMode::Live || $run->trigger !== RunTrigger::Activation) {
            return null;
        }

        $preview = SourceRun::query()
            ->where('source_id', $source->id)
            ->where('mode', RunMode::Preview)
            ->where('id', '<', $run->id)
            ->whereNotIn('status', [RunStatus::Queued, RunStatus::Running])
            ->orderByDesc('id')
            ->first();

        $cutoff = CarbonImmutable::now()->subMinutes((int) config('ingest.preview_reuse_minutes', 60));

        if ($preview === null
            || $preview->status !== RunStatus::Succeeded
            || $preview->raw_path === null
            || $preview->finished_at === null
            || ! $preview->finished_at->greaterThan($cutoff)
            || ($preview->preview['url'] ?? null) !== $source->url) {
            return null;
        }

        $body = $this->raw->get($source->id, $preview->id);

        if ($body === null || hash('sha256', $body) !== $preview->raw_sha256) {
            return null;
        }

        return FetchResult::ok($body, $preview->final_url ?? $source->url, $preview->http_status ?? 200);
    }

    /**
     * The later of $current and the item's London start date (its next
     * instance, else its first date).
     */
    private function laterDate(?string $current, ExtractedItem $item): ?string
    {
        $facts = $item->facts;

        if ($facts === null) {
            return $current;
        }

        $date = $facts->nextInstanceStartUtc?->setTimezone(self::LONDON)->toDateString() ?? $facts->startDate;

        return $current === null || strcmp($date, $current) > 0 ? $date : $current;
    }

    private static function fits(?string $value, int $length): ?string
    {
        return $value !== null && $value !== '' && strlen($value) <= $length ? $value : null;
    }

    private function finish(
        SourceRun $run,
        Source $source,
        RunStatus $status,
        ?FetchFailureKind $failureKind = null,
        ?string $message = null,
        ?FetchResult $result = null,
    ): void {
        $nowUtc = CarbonImmutable::now()->utc();

        $run->forceFill([
            'status' => $status,
            'failure_kind' => $failureKind?->value,
            'error_message' => self::errorMessage($message),
            'finished_at' => $nowUtc,
        ])->save();

        if ($run->mode !== RunMode::Live) {
            // A preview is not a check: no source state, validators or health.
            return;
        }

        $sourceState = ['last_run_at' => $nowUtc];

        if ($status === RunStatus::Succeeded || $status === RunStatus::NotModified) {
            $sourceState['last_success_at'] = $nowUtc;
        }

        if ($status === RunStatus::Succeeded) {
            $sourceState['last_items_found'] = $run->items_found;
        }

        if ($result !== null && ($status === RunStatus::Succeeded || $status === RunStatus::NotModified)) {
            // A validator too long for its column is dropped, never cut:
            // a cut validator would never match.
            $sourceState['etag'] = self::fits($result->etag, 255);
            $sourceState['last_modified'] = self::fits($result->lastModified, 64);
        }

        $source->forceFill($sourceState)->save();

        // Health, structure alert and is_complete; before missing detection (03-09).
        $this->health->record($run);
    }
}
