<?php

namespace App\Ingestion\Pipeline;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\SourceItemStatus;
use App\Ingestion\Data\NormalisedFacts;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Two-complete-run missing detection (PIPE-08, RESEARCH Pattern 5, D-20).
 *
 * Runs only after a LIVE, SUCCEEDED, COMPLETE run (is_complete, set by
 * SourceHealthTracker just before) of a source with
 * supports_missing_detection. Failed, blocked, not-modified, preview and
 * incomplete (zero-item or shrunken) runs never touch the counters
 * (SRC-09), so an item merely absent from a partial fetch is never
 * cancelled.
 *
 * Counted: active items of the source not seen in this run whose
 * last_known_start_at is after now, before the end of the run's
 * observed_until London day, and inside calendar.horizon_days. Every item
 * the run listed was marked seen first (SourceItemRecorder), skipped ones
 * included, so an item a title rule now skips, one moved beyond the window
 * or one that became unparseable is present, not missing. Seeing an item
 * resets its count, so only consecutive complete runs add up.
 *
 * At 2 the item becomes missing and ChangeDetector::missing() decides: a
 * linked item gets a whole-event cancellation draft (status active →
 * missing), a pending new draft is superseded. items_missing on the run is
 * the number of items counted in it.
 */
final class MissingItemDetector
{
    public const int RUNS_TO_MISSING = 2;

    private const string LONDON = 'Europe/London';

    public function __construct(
        private readonly ChangeDetector $detector,
        private readonly SourceItemRecorder $recorder,
    ) {}

    /**
     * @return list<int> ids of the items that became missing in this run
     */
    public function detect(SourceRun $run): array
    {
        $source = Source::query()->find($run->source_id);

        if ($source === null
            || ! $source->supports_missing_detection
            || $run->mode !== RunMode::Live
            || $run->status !== RunStatus::Succeeded
            || ! $run->is_complete
            || $run->observed_until === null) {
            return [];
        }

        $nowLondon = CarbonImmutable::now(self::LONDON);
        $observedEnd = CarbonImmutable::createFromFormat('!Y-m-d', $run->observed_until->format('Y-m-d'), self::LONDON);

        if (! $observedEnd instanceof CarbonImmutable) {
            return [];
        }

        $observedEnd = $observedEnd->addDay();
        $horizonEnd = $nowLondon->addDays((int) config('calendar.horizon_days', 90));
        $upper = $observedEnd->lessThan($horizonEnd) ? $observedEnd : $horizonEnd;

        $counted = DB::update(
            "update source_items set missing_runs = missing_runs + 1, updated_at = ?
             where source_id = ? and status = 'active' and last_seen_run_id is distinct from ?
               and last_known_start_at > ? and last_known_start_at < ?",
            [
                $nowLondon->utc()->format('Y-m-d H:i:s'),
                $source->id,
                $run->id,
                $nowLondon->utc()->format('Y-m-d H:i:s'),
                $upper->utc()->format('Y-m-d H:i:s'),
            ],
        );

        $run->forceFill(['items_missing' => $counted])->save();

        $ids = SourceItem::query()
            ->where('source_id', $source->id)
            ->where('status', SourceItemStatus::Active)
            ->where('missing_runs', '>=', self::RUNS_TO_MISSING)
            ->where(fn ($query) => $query->whereNull('last_seen_run_id')->orWhere('last_seen_run_id', '<>', $run->id))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $missing = [];

        foreach ($ids as $id) {
            if ($this->markMissing($id, $source, $nowLondon)) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * One item, one transaction: locked first, then its draft (the
     * recorder's order), re-checked on the locked row.
     */
    private function markMissing(int $id, Source $source, CarbonImmutable $nowLondon): bool
    {
        return DB::transaction(function () use ($id, $source, $nowLondon): bool {
            $item = SourceItem::query()->whereKey($id)->lockForUpdate()->first();

            if ($item === null || $item->status !== SourceItemStatus::Active || $item->missing_runs < self::RUNS_TO_MISSING) {
                return false;
            }

            $item->forceFill(['status' => SourceItemStatus::Missing])->save();

            if (! is_array($item->payload)) {
                return true;
            }

            $pending = SourceItemRecorder::pendingDraft($item);
            $link = SourceItemRecorder::link($item);
            $verdict = $this->detector->missing($item, $pending, $link);

            $this->recorder->act($item, $source, $verdict, $pending, $link, NormalisedFacts::fromPayload($item->payload), $item->parse_errors, $nowLondon);

            return true;
        });
    }
}
