<?php

namespace App\Ingestion\Health;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\SourceHealth;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Source health, structure alerts and run completeness (SRC-08, RESEARCH
 * "State machines" and Pattern 5). Called once for every finished run:
 * by IngestRun after it finishes a run, by FetchSourceJob after it marks a
 * run failed/abandoned, and by the ingest:dispatch-due janitor for each run
 * it abandons.
 *
 * Only live runs count; previews and unfinished runs change nothing.
 * - succeeded / not_modified: consecutive_failures 0, healthy.
 * - failed (any kind, abandoned included): consecutive_failures + 1, then
 *   SourceHealth::forFailures() (degraded at 3, unhealthy at 5).
 * - blocked (robots.txt): neither the count nor the health moves.
 *
 * A succeeded run with zero items whose previous live succeeded run had
 * items sets run.structure_alert and sources.structure_alert_at (possible
 * structure change). A later succeeded run with items clears
 * structure_alert_at: the feed is listing events again.
 *
 * is_complete (the only runs missing-item detection reads, 03-09): a live
 * succeeded run (so its body passed validation) with items_found > 0 and
 * items_found at least half of the previous complete run's. WR-02: a feed
 * that lastingly shrinks must not switch detection off for good, so a run
 * is also complete when it is the STEADY_RUNS-th succeeded run in a row
 * (previous ones incomplete) whose items_found are all within
 * STEADY_TOLERANCE_PERCENT of this run's: the new level becomes the
 * reference. A one-off short feed (or one that keeps shrinking) stays
 * incomplete.
 *
 * Writes only the source's health columns and the run's two flags, inside
 * one transaction that locks the source row. Never touches items, drafts,
 * events or occurrences (SRC-09).
 */
final class SourceHealthTracker
{
    /** Succeeded runs in a row at a steady new level that re-baseline completeness (WR-02). */
    public const int STEADY_RUNS = 3;

    /** How far (percent of the larger count) the steady runs may differ. */
    public const int STEADY_TOLERANCE_PERCENT = 20;

    public function record(SourceRun $run): void
    {
        if ($run->mode !== RunMode::Live || in_array($run->status, [RunStatus::Queued, RunStatus::Running, RunStatus::Blocked], true)) {
            return;
        }

        DB::transaction(function () use ($run): void {
            $source = Source::query()->lockForUpdate()->findOrFail($run->source_id);

            match ($run->status) {
                RunStatus::Succeeded => $this->succeeded($run, $source),
                RunStatus::NotModified => $source->forceFill(['consecutive_failures' => 0, 'health' => SourceHealth::Healthy]),
                default => $this->failed($source),
            };

            $source->save();
        });
    }

    private function failed(Source $source): void
    {
        $failures = $source->consecutive_failures + 1;

        $source->forceFill([
            'consecutive_failures' => $failures,
            'health' => SourceHealth::forFailures($failures),
        ]);
    }

    private function succeeded(SourceRun $run, Source $source): void
    {
        $source->forceFill(['consecutive_failures' => 0, 'health' => SourceHealth::Healthy]);

        $previousSuccess = $this->previousLive($run)->where('status', RunStatus::Succeeded)->first();
        $previousComplete = $this->previousLive($run)->where('is_complete', true)->first();

        $structureAlert = $run->items_found === 0
            && $previousSuccess !== null
            && $previousSuccess->items_found > 0;

        $isComplete = $run->items_found > 0
            && ($previousComplete === null
                || $run->items_found * 2 >= $previousComplete->items_found
                || $this->steadyNewLevel($run));

        $run->forceFill([
            'structure_alert' => $structureAlert,
            'is_complete' => $isComplete,
        ])->save();

        if ($structureAlert) {
            $source->forceFill(['structure_alert_at' => CarbonImmutable::now()->utc()]);
        } elseif ($run->items_found > 0) {
            $source->forceFill(['structure_alert_at' => null]);
        }
    }

    /**
     * WR-02: the STEADY_RUNS - 1 succeeded live runs before this one are all
     * incomplete, found items, and are within STEADY_TOLERANCE_PERCENT of
     * this run's count, so the feed has settled at a new, smaller size.
     */
    private function steadyNewLevel(SourceRun $run): bool
    {
        $earlier = $this->previousLive($run)
            ->where('status', RunStatus::Succeeded)
            ->limit(self::STEADY_RUNS - 1)
            ->get(['id', 'items_found', 'is_complete']);

        if ($earlier->count() < self::STEADY_RUNS - 1) {
            return false;
        }

        return $earlier->every(fn (SourceRun $previous): bool => ! $previous->is_complete
            && $previous->items_found > 0
            && self::close($previous->items_found, $run->items_found));
    }

    private static function close(int $a, int $b): bool
    {
        return abs($a - $b) * 100 <= max($a, $b) * self::STEADY_TOLERANCE_PERCENT;
    }

    /**
     * Earlier live runs of the same source, newest first.
     *
     * @return Builder<SourceRun>
     */
    private function previousLive(SourceRun $run): Builder
    {
        return SourceRun::query()
            ->where('source_id', $run->source_id)
            ->where('mode', RunMode::Live)
            ->where('id', '<', $run->id)
            ->orderByDesc('id');
    }
}
