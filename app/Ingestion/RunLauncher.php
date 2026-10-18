<?php

namespace App\Ingestion;

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\FetchSourceJob;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts live runs and works out when a source is next due (SRC-03, SRC-04,
 * D-02). Shared by the "Run now" action (03-15) and ingest:dispatch-due.
 *
 * The source_runs_one_active partial unique index is the guarantee that a
 * source never has two queued or running runs: the insert runs in its own
 * savepoint, so a refused insert never aborts a caller's transaction, and
 * becomes RunAlreadyActive. The job's ShouldBeUnique lock only cuts noise.
 * Nothing is fetched here: the queued FetchSourceJob does the work.
 */
final class RunLauncher
{
    /**
     * Insert a queued live run and queue its job (after commit).
     *
     * @throws RunAlreadyActive when the source already has a queued or running run
     */
    public function runNow(Source $source, RunTrigger $trigger = RunTrigger::Manual): SourceRun
    {
        $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => $trigger]);
        $run->forceFill([
            'source_id' => $source->id,
            'status' => RunStatus::Queued,
            'queued_at' => CarbonImmutable::now()->utc(),
        ]);

        try {
            DB::transaction(fn () => $run->save());
        } catch (UniqueConstraintViolationException $e) {
            throw new RunAlreadyActive($source->id, $e);
        }

        FetchSourceJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    /**
     * When the source is next due: its check_time (London wall clock,
     * default 05:30) one day ahead for daily sources, seven days ahead for
     * weekly ones, plus a deterministic (id × 7) mod jitter_minutes spread so
     * sources do not all start in the same minute. The maths runs on a
     * Europe/London date so the slot keeps its wall-clock time across DST
     * (05:30 on Sun 25 Oct 2026 is 05:30 UTC); the result is UTC.
     */
    public function nextCheckAt(Source $source, CarbonImmutable $nowLondon): CarbonImmutable
    {
        $today = $nowLondon->setTimezone('Europe/London');
        $days = $source->check_frequency === 'weekly' ? 7 : 1;
        $day = $today->startOfDay()->addDays($days);

        [$hour, $minute] = array_map('intval', array_pad(explode(':', $source->check_time), 2, '0'));

        $slot = CarbonImmutable::create($day->year, $day->month, $day->day, $hour, $minute, 0, 'Europe/London');
        $jitterRange = max(1, (int) config('ingest.schedule.jitter_minutes', 20));

        return $slot->addMinutes(($source->id * 7) % $jitterRange)->utc();
    }
}
