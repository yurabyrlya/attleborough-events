<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Console\Commands\IngestDispatchDue;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\SourceHealth;
use App\Filament\Resources\Sources\Actions\SourceActions;
use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\SourceResource;
use App\Ingestion\Http\HostThrottle;
use App\Models\Source;
use App\Models\SourceRun;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Edits a source's configuration (default record save, conflict C18) and
 * hosts the onboarding flow (D-01): Test run, the "Latest test run"
 * preview, Create drafts, Run now and Pause, plus the run log (relation
 * manager). Settings saved while a run is queued or running apply from the
 * next run that starts: a running run already holds its own copy of the
 * source, and runs are never rewritten by a save.
 *
 * While the source has a queued or running run the preview panel carries
 * wire:poll.3s, which re-renders the whole page (header actions included)
 * every 3 seconds; with no active run nothing polls (RESEARCH Pitfall 13).
 */
class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    /** Most preview rows shown (IngestRun stores at most 200). */
    public const int PREVIEW_ROW_LIMIT = 200;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = SourceForm::prepareForSave($data);

        // Keep parser_config keys the form does not edit.
        $data['parser_config'] = [...$this->source()->parser_config, ...($data['parser_config'] ?? [])];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return SourceActions::forEditPage();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Paused after repeated failures')
                    ->description('The last checks failed, so the scheduler skips this source. "Run now" still works, and one successful check makes it healthy again.')
                    ->danger()
                    ->visible(fn (): bool => $this->source()->health === SourceHealth::Unhealthy),
                Callout::make('Blocked by robots.txt')
                    ->description("The site's robots.txt does not allow the crawler to fetch this feed, so nothing was read.")
                    ->warning()
                    ->visible(fn (): bool => $this->source()->latestLiveRun?->status === RunStatus::Blocked),
                Callout::make('Possible structure change')
                    ->description('The last check found no items after earlier checks found some. The feed may have moved or changed format.')
                    ->warning()
                    ->visible(fn (): bool => $this->source()->structure_alert_at !== null),
                Callout::make(fn (): string => (string) $this->crawlDelayWarning())
                    ->description(fn (): string => $this->crawlDelayDescription())
                    ->warning()
                    ->visible(fn (): bool => $this->crawlDelayWarning() !== null),
                Section::make('Latest test run')
                    ->key('latest-test-run')
                    ->schema([
                        View::make('filament.resources.sources.preview')
                            ->viewData(fn (): array => $this->previewViewData()),
                    ]),
                $this->getFormContentComponent(),
                $this->getRelationManagersContentComponent(),
            ]);
    }

    /**
     * Seconds the host asks between requests (robots.txt Crawl-delay, as
     * cached in crawl_hosts by RobotsPolicy), or null when none is known.
     */
    public function crawlDelaySeconds(): ?int
    {
        $delay = DB::table('crawl_hosts')->where('host', HostThrottle::keyFor($this->source()->url))->value('crawl_delay_seconds');

        return is_numeric($delay) ? (int) $delay : null;
    }

    /**
     * Pitfall 7: a Crawl-delay the schedule cannot honour. A check needs up
     * to two requests to the host (robots.txt, then the feed), and a run may
     * wait in the queue for IngestDispatchDue::QUEUED_TIMEOUT_MINUTES before
     * it is marked abandoned, so the warning shows when the delay is longer
     * than the check interval or than that queue wait.
     */
    public function crawlDelayWarning(): ?string
    {
        $delay = $this->crawlDelaySeconds();

        if ($delay === null) {
            return null;
        }

        $interval = $this->source()->check_frequency === 'weekly' ? 7 * 86400 : 86400;
        $queueWait = IngestDispatchDue::QUEUED_TIMEOUT_MINUTES * 60;

        return match (true) {
            $delay > $interval => "Crawl-delay {$delay}s is longer than the check interval",
            $delay > $queueWait => "Crawl-delay {$delay}s is longer than the check interval allows",
            default => null,
        };
    }

    /**
     * The data the preview view renders: the latest test run (queued_at,
     * then id) with its rows formatted for display, and whether to poll.
     *
     * @return array{run: SourceRun|null, polling: bool, wouldCreate: int, skipped: array<string, int>, rows: list<array{title: string, starts: string, verdict: string, reason: string, duplicate: string|null}>, checkedAt: string|null}
     */
    public function previewViewData(): array
    {
        $source = $this->source();
        $run = $source->runs()
            ->where('mode', RunMode::Preview->value)
            ->orderByDesc('queued_at')
            ->orderByDesc('id')
            ->first();

        $preview = $run === null ? [] : ($run->preview ?? []);
        $skipped = is_array($preview['skipped'] ?? null) ? $preview['skipped'] : [];
        $rows = is_array($preview['rows'] ?? null) ? array_slice($preview['rows'], 0, self::PREVIEW_ROW_LIMIT) : [];
        $horizon = (int) config('calendar.horizon_days', 90);

        return [
            'run' => $run,
            'polling' => $source->hasActiveRun(),
            'wouldCreate' => (int) ($preview['would_create'] ?? 0),
            'skipped' => [
                'Past' => (int) ($skipped['past'] ?? 0),
                "Beyond {$horizon} days" => (int) ($skipped['far_future'] ?? 0),
                'Title rule' => (int) ($skipped['rule'] ?? 0),
                'Parse error' => (int) ($skipped['parse_error'] ?? 0),
            ],
            'rows' => array_values(array_map(fn (mixed $row): array => self::previewRow(is_array($row) ? $row : []), $rows)),
            'checkedAt' => $run?->finished_at !== null
                ? LondonFormatter::shortDate($run->finished_at).', '.LondonFormatter::time($run->finished_at)
                : null,
        ];
    }

    /**
     * One stored preview row for display: London date and time ("Sat 14
     * Nov, 7:30pm" / "Sat 14 Nov"), plain-English verdict and reason, and
     * the possible-duplicate note when the row carries one (03-11).
     *
     * @param  array<array-key, mixed>  $row
     * @return array{title: string, starts: string, verdict: string, reason: string, duplicate: string|null}
     */
    public static function previewRow(array $row): array
    {
        $horizon = (int) config('calendar.horizon_days', 90);

        $verdict = match ($row['verdict'] ?? null) {
            'new' => 'Would draft',
            'changed' => 'Changed',
            'unchanged' => 'Already known',
            'skipped' => 'Skipped',
            default => '',
        };

        $reason = match ($row['reason'] ?? null) {
            'past' => 'Past',
            'far_future' => "Beyond {$horizon} days",
            'rule' => 'Title rule',
            'parse_error' => 'Parse error',
            default => '',
        };

        $duplicate = $row['duplicate'] ?? null;
        $duplicateTitle = is_array($duplicate) ? ($duplicate['title'] ?? null) : $duplicate;

        return [
            'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
            'starts' => self::previewStart($row['starts'] ?? null),
            'verdict' => $verdict,
            'reason' => $reason,
            'duplicate' => match (true) {
                $duplicate === null || $duplicate === false || $duplicate === '' => null,
                is_string($duplicateTitle) && $duplicateTitle !== '' => 'Possible duplicate of '.$duplicateTitle,
                default => 'Possible duplicate',
            },
        ];
    }

    /**
     * "Y-m-d H:i" or "Y-m-d" (London wall clock, IngestRun) → display text.
     */
    private static function previewStart(mixed $starts): string
    {
        if (! is_string($starts)) {
            return '';
        }

        // Carbon throws (rather than returning false) when the text does not
        // fit the format, so pick the format by shape first.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $starts) === 1) {
            $timed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $starts, 'Europe/London');

            return $timed instanceof CarbonImmutable ? LondonFormatter::shortDate($timed).', '.LondonFormatter::time($timed) : $starts;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) === 1) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $starts, 'Europe/London');

            return $day instanceof CarbonImmutable ? LondonFormatter::shortDate($day) : $starts;
        }

        return $starts;
    }

    private function crawlDelayDescription(): string
    {
        $delay = (int) $this->crawlDelaySeconds();

        return "This site asks crawlers to wait {$delay} seconds between requests. A check needs up to two requests, and a check that waits more than "
            .(IngestDispatchDue::QUEUED_TIMEOUT_MINUTES / 60).' hours in the queue is marked abandoned, so checks of this source may never finish. Ask the site owner to allow the crawler, or check it less often.';
    }

    private function source(): Source
    {
        $record = $this->getRecord();

        if (! $record instanceof Source) {
            throw new InvalidArgumentException('EditSource expects a Source record.');
        }

        return $record;
    }
}
