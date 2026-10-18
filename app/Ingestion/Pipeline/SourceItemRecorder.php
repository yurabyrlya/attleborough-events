<?php

namespace App\Ingestion\Pipeline;

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Enums\SourceItemStatus;
use App\Ingestion\Data\ChangeVerdict;
use App\Ingestion\Data\ExtractedItem;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Data\RecordOutcome;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records one extracted item of a live run (03-05, 03-09): one DB
 * transaction per item, returning the single bucket the item is counted in
 * (SRC-07) and the drafts it wrote. verdict() returns the same bucket for a
 * preview run (D-01) without writing anything.
 *
 * Order:
 * 1. A known identity is marked seen in this run first (last_seen_run_id,
 *    last_seen_at, missing_runs 0), whatever bucket follows, so an item the
 *    feed still lists is never treated as missing (PIPE-08). An item that
 *    had gone missing is active again and its pending missing-cancellation
 *    draft is superseded.
 * 2. No usable facts → skipped_parse_error (the item's parse errors kept,
 *    no draft, also for linked items).
 * 3. Tracked items (linked to an event through event_sources, or with a
 *    pending new draft) skip the title-rule and window buckets: only a past
 *    item is just touched; everything else goes to change detection, so a
 *    linked event moved beyond the window or newly matching a skip rule is
 *    still compared (counted changed / unchanged).
 * 4. Other items: title rules (D-04) → skipped_rule; past / far future
 *    (D-20) → skipped_past / skipped_far_future.
 * 5. Known item → ChangeDetector when its raw_hash changed, or when it has
 *    no pending draft and content_hash ≠ accepted_hash (a change held back
 *    by a cancelled-part baseline move, 03-10, or an undecided item that
 *    lost its draft); otherwise unchanged. Content and hashes are stored;
 *    when content equals the baseline the accepted payload follows quietly
 *    (D-06). The verdict is then acted on (act()).
 * 6. New identity → a source item (active, review pending) and one pending
 *    New draft, or, when the feed already marks it STATUS:CANCELLED, a
 *    cancelled item and no draft (PIPE-07).
 *
 * Skipped identities that are not yet known get no source item: an item
 * far in the future today must still be drafted when it enters the window.
 * Lock order inside the item transaction: source item → draft.
 */
final class SourceItemRecorder
{
    public const string NEW = 'new';

    public const string CHANGED = 'changed';

    public const string UNCHANGED = 'unchanged';

    public const string SKIPPED_PAST = 'skipped_past';

    public const string SKIPPED_FAR_FUTURE = 'skipped_far_future';

    public const string SKIPPED_RULE = 'skipped_rule';

    public const string SKIPPED_PARSE_ERROR = 'skipped_parse_error';

    /**
     * The source_runs counter each bucket increments.
     */
    public const array COUNTER = [
        self::NEW => 'items_new',
        self::CHANGED => 'items_changed',
        self::UNCHANGED => 'items_unchanged',
        self::SKIPPED_PAST => 'skipped_past',
        self::SKIPPED_FAR_FUTURE => 'skipped_far_future',
        self::SKIPPED_RULE => 'skipped_rule',
        self::SKIPPED_PARSE_ERROR => 'skipped_parse_error',
    ];

    public function __construct(
        private readonly WindowClassifier $window,
        private readonly DraftWriter $drafts,
        private readonly ChangeDetector $detector,
    ) {}

    public function record(SourceRun $run, Source $source, ExtractedItem $item, CarbonImmutable $nowLondon): RecordOutcome
    {
        return DB::transaction(function () use ($run, $source, $item, $nowLondon): RecordOutcome {
            $nowUtc = $nowLondon->utc();

            $existing = SourceItem::query()
                ->where('source_id', $source->id)
                ->where('external_id', $item->externalId)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $bucket = $this->classify($source, $item, null, $nowLondon);

                if ($bucket !== self::NEW) {
                    return new RecordOutcome($bucket);
                }

                return $this->createNew($run, $source, $item, $nowUtc);
            }

            $existing->forceFill([
                'last_seen_run_id' => $run->id,
                'last_seen_at' => $nowUtc,
                'missing_runs' => 0,
            ]);

            $this->reappear($existing);

            $facts = $item->facts;

            if ($facts === null) {
                $existing->forceFill(['parse_errors' => $item->parseErrors])->save();

                return new RecordOutcome(self::SKIPPED_PARSE_ERROR);
            }

            $pending = self::pendingDraft($existing);
            $link = self::link($existing);

            if ($link !== null || $pending?->kind === DraftKind::New) {
                if ($this->window->classify($facts, $nowLondon) === WindowClassifier::PAST) {
                    $existing->save();

                    return new RecordOutcome(self::SKIPPED_PAST);
                }
            } else {
                $bucket = $this->classify($source, $item, $existing, $nowLondon);

                if (str_starts_with($bucket, 'skipped_')) {
                    $existing->save();

                    return new RecordOutcome($bucket);
                }
            }

            return $this->detectChange($source, $item, $facts, $existing, $pending, $link, $nowLondon);
        });
    }

    /**
     * The bucket record() would put this item in, without writing or
     * locking anything: the D-01 preview's verdict. Shares classify() with
     * record(), so a preview and the live run that follows cannot drift.
     *
     * @return self::NEW|self::CHANGED|self::UNCHANGED|self::SKIPPED_PAST|self::SKIPPED_FAR_FUTURE|self::SKIPPED_RULE|self::SKIPPED_PARSE_ERROR
     */
    public function verdict(Source $source, ExtractedItem $item, CarbonImmutable $nowLondon): string
    {
        $existing = SourceItem::query()
            ->where('source_id', $source->id)
            ->where('external_id', $item->externalId)
            ->first();

        return $this->classify($source, $item, $existing, $nowLondon);
    }

    /**
     * Acts on a change verdict for a locked source item (03-09): writes,
     * replaces or supersedes its draft. Shared by record() and
     * MissingItemDetector. A pending draft decided meanwhile is never
     * overwritten: the verdict is worked out again without it, once.
     *
     * @param  list<string>  $parseErrors
     * @return array{created: int, replaced: int}
     */
    public function act(
        SourceItem $item,
        Source $source,
        ChangeVerdict $verdict,
        ?EventDraft $pending,
        ?EventSource $link,
        NormalisedFacts $facts,
        array $parseErrors,
        CarbonImmutable $nowLondon,
        bool $retry = true,
    ): array {
        $none = ['created' => 0, 'replaced' => 0];
        $event = $link?->event;

        switch ($verdict->type) {
            case ChangeVerdict::SUPERSEDE:
                if ($pending !== null) {
                    $this->drafts->supersedeFromSource($pending);
                }

                return $none;

            case ChangeVerdict::REDRAFT:
                $item->forceFill(['review_outcome' => 'pending'])->save();
                $this->drafts->createFromSource($this->draftData($facts, $source, $parseErrors), DraftKind::New, $source, $item, $facts->categories);

                return ['created' => 1, 'replaced' => 0];

            case ChangeVerdict::REPLACE:
                return $this->replace($item, $source, $verdict, $pending, $facts, $parseErrors, $nowLondon, $retry);

            case ChangeVerdict::UPDATE:
            case ChangeVerdict::CANCEL:
                if (! $event instanceof Event) {
                    return $none;
                }

                $kind = $verdict->type === ChangeVerdict::UPDATE ? DraftKind::Update : DraftKind::Cancellation;

                if ($pending !== null && $pending->kind === $kind) {
                    return $this->replace($item, $source, $verdict, $pending, $facts, $parseErrors, $nowLondon, $retry);
                }

                if ($pending !== null) {
                    // A cancellation replaces a pending update (PIPE-07).
                    $this->drafts->supersedeFromSource($pending);
                }

                $this->drafts->createChangeDraft(
                    $this->draftData($facts, $source, $parseErrors),
                    $kind,
                    $source,
                    $item,
                    $event,
                    $verdict->diff,
                    $verdict->targetKeys[0] ?? null,
                    $verdict->relevantStartsAt,
                    $facts->categories,
                );

                return ['created' => 1, 'replaced' => 0];

            default:
                return $none;
        }
    }

    /**
     * The pending draft of a source item, locked (at most one: PIPE-06).
     */
    public static function pendingDraft(SourceItem $item): ?EventDraft
    {
        return EventDraft::query()
            ->where('source_item_id', $item->id)
            ->where('review_status', ReviewStatus::PendingReview)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The event link of a source item (approved or duplicate, D-11).
     */
    public static function link(SourceItem $item): ?EventSource
    {
        return EventSource::query()->with('event')->where('source_item_id', $item->id)->first();
    }

    /**
     * @param  list<string>  $parseErrors
     * @return array{created: int, replaced: int}
     */
    private function replace(
        SourceItem $item,
        Source $source,
        ChangeVerdict $verdict,
        ?EventDraft $pending,
        NormalisedFacts $facts,
        array $parseErrors,
        CarbonImmutable $nowLondon,
        bool $retry,
    ): array {
        if ($pending === null) {
            return ['created' => 0, 'replaced' => 0];
        }

        $replaced = $this->drafts->replacePendingFromSource(
            $pending,
            $this->draftData($facts, $source, $parseErrors),
            $facts->categories,
            $pending->kind === DraftKind::New ? null : $verdict->diff,
            $verdict->targetKeys[0] ?? null,
            $verdict->relevantStartsAt,
        );

        if ($replaced !== null) {
            return ['created' => 0, 'replaced' => 1];
        }

        if (! $retry) {
            return ['created' => 0, 'replaced' => 0];
        }

        // Decided while this run was replacing it (PIPE-06 concurrency):
        // never overwrite the decision; decide again from the fresh state.
        $item->refresh();
        $freshPending = self::pendingDraft($item);
        $freshLink = self::link($item);
        $again = $this->detector->detect($item, $facts, (string) $item->content_hash, $freshPending, $freshLink, $nowLondon);

        return $this->act($item, $source, $again, $freshPending, $freshLink, $facts, $parseErrors, $nowLondon, false);
    }

    private function detectChange(
        Source $source,
        ExtractedItem $item,
        NormalisedFacts $facts,
        SourceItem $existing,
        ?EventDraft $pending,
        ?EventSource $link,
        CarbonImmutable $nowLondon,
    ): RecordOutcome {
        $rawChanged = $existing->raw_hash !== $item->rawHash;
        $deferred = $pending === null && $existing->content_hash !== $existing->accepted_hash;

        if (! $rawChanged && ! $deferred) {
            $existing->save();

            return new RecordOutcome(self::UNCHANGED);
        }

        $contentHash = (string) $item->contentHash;
        $verdict = $this->detector->detect($existing, $facts, $contentHash, $pending, $link, $nowLondon);
        $wasCancelled = $existing->status === SourceItemStatus::Cancelled;

        $this->storeContent($existing, $item);

        if ($existing->accepted_hash !== null && $existing->accepted_hash === $contentHash) {
            // D-06: only wording changed; the baseline follows quietly.
            $existing->forceFill(['accepted_payload' => $existing->payload]);
        }

        $existing->save();

        $written = $this->act($existing, $source, $verdict, $pending, $link, $facts, $item->parseErrors, $nowLondon);
        $raised = $written['created'] + $written['replaced'] > 0;

        return new RecordOutcome(
            $rawChanged || $raised ? self::CHANGED : self::UNCHANGED,
            $written['created'],
            $written['replaced'],
            $verdict->type === ChangeVerdict::CANCEL || ($facts->cancelled && ! $wasCancelled),
        );
    }

    /**
     * PIPE-08: a missing item listed again is active, and the cancellation
     * its absence raised is withdrawn while still pending.
     */
    private function reappear(SourceItem $item): void
    {
        if ($item->status !== SourceItemStatus::Missing) {
            return;
        }

        $item->forceFill(['status' => SourceItemStatus::Active]);

        $pending = self::pendingDraft($item);

        if ($pending !== null && $pending->kind === DraftKind::Cancellation && array_key_exists('status', $pending->diff ?? [])) {
            $this->drafts->supersedeFromSource($pending);
        }
    }

    /**
     * The bucket for an item, without writing anything.
     *
     * @return self::NEW|self::CHANGED|self::UNCHANGED|self::SKIPPED_PAST|self::SKIPPED_FAR_FUTURE|self::SKIPPED_RULE|self::SKIPPED_PARSE_ERROR
     */
    private function classify(Source $source, ExtractedItem $item, ?SourceItem $existing, CarbonImmutable $nowLondon): string
    {
        $facts = $item->facts;

        if ($facts === null) {
            return self::SKIPPED_PARSE_ERROR;
        }

        if (! TitleRules::allows($source->title_rules, $facts->title)) {
            return self::SKIPPED_RULE;
        }

        $windowClass = $this->window->classify($facts, $nowLondon);

        if ($windowClass === WindowClassifier::PAST) {
            return self::SKIPPED_PAST;
        }

        if ($windowClass === WindowClassifier::FAR_FUTURE) {
            return self::SKIPPED_FAR_FUTURE;
        }

        if ($existing === null) {
            return self::NEW;
        }

        return $existing->raw_hash === $item->rawHash ? self::UNCHANGED : self::CHANGED;
    }

    /**
     * A first sighting: a source item and its New draft, or a cancelled
     * item and no draft when the feed already cancels it (PIPE-07: nothing
     * was published, so there is nothing to review).
     */
    private function createNew(SourceRun $run, Source $source, ExtractedItem $item, CarbonImmutable $nowUtc): RecordOutcome
    {
        $facts = $item->facts;

        if ($facts === null) {
            return new RecordOutcome(self::SKIPPED_PARSE_ERROR);
        }

        $sourceItem = new SourceItem;
        $sourceItem->forceFill([
            'source_id' => $source->id,
            'external_id' => $item->externalId,
            'review_outcome' => 'pending',
            'first_seen_run_id' => $run->id,
            'last_seen_run_id' => $run->id,
            'first_seen_at' => $nowUtc,
            'last_seen_at' => $nowUtc,
            'missing_runs' => 0,
        ]);
        $this->storeContent($sourceItem, $item);
        $sourceItem->save();

        if ($facts->cancelled) {
            return new RecordOutcome(self::NEW, cancelled: true);
        }

        $this->drafts->createFromSource($this->draftData($facts, $source, $item->parseErrors), DraftKind::New, $source, $sourceItem, $facts->categories);

        return new RecordOutcome(self::NEW, draftsCreated: 1);
    }

    /**
     * @param  list<string>  $parseErrors
     */
    private function draftData(NormalisedFacts $facts, Source $source, array $parseErrors): DraftData
    {
        return DraftData::fromSourceItem(
            $facts,
            $source,
            $this->description($facts->description, $source),
            $parseErrors,
            $facts->toPayload(),
        );
    }

    private function storeContent(SourceItem $sourceItem, ExtractedItem $item): void
    {
        $sourceItem->forceFill([
            'raw_fragment' => $item->rawFragment,
            'raw_hash' => $item->rawHash,
            'content_hash' => $item->contentHash,
            'payload' => $item->facts?->toPayload(),
            'parse_errors' => $item->parseErrors,
            'last_known_start_at' => $item->facts?->nextInstanceStartUtc,
            'status' => $item->facts?->cancelled === true ? SourceItemStatus::Cancelled : SourceItemStatus::Active,
        ]);
    }

    /**
     * The draft description: the excerpt (PIPE-09), or the D-21 fallback
     * when the feed text is empty or nothing is left after stripping.
     */
    private function description(?string $full, Source $source): string
    {
        return ExcerptBuilder::make($full) ?? self::fallbackDescription($source);
    }

    /**
     * D-21: the neutral description for an item without one.
     */
    public static function fallbackDescription(Source $source): string
    {
        return 'Details from '.$source->name.'.';
    }
}
