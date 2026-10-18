<?php

namespace App\Jobs;

use App\Enums\FetchFailureKind;
use App\Enums\RunStatus;
use App\Ingestion\Health\SourceHealthTracker;
use App\Ingestion\Http\HostBusy;
use App\Ingestion\IngestRun;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued source run (03-05). Thin: takes only the run id (no
 * serialised models), claims the run with a guarded queued → running
 * UPDATE (0 rows: someone else has it, or it was swept, so return) and
 * hands it to IngestRun.
 *
 * HostBusy (the host's next polite slot is too far away) puts the run back
 * to queued and releases the job for that many seconds. Released attempts
 * are not failures: retryUntil() (fixed at dispatch, 6 h) replaces --tries,
 * and maxExceptions = 1 fails the job on its first real exception (RESEARCH
 * F8, Pitfall 10). Any other Throwable marks the run failed/abandoned,
 * records it with SourceHealthTracker (a live run counts as a failure,
 * SRC-08) and is rethrown. timeout 75 s stays below the database queue's
 * retry_after (90).
 *
 * Properties, not attributes, like QueueHeartbeat (conflict C8). Unique per
 * source; the source_runs_one_active index is the real guarantee (SRC-04).
 */
class FetchSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public int $timeout = 75;

    public int $maxExceptions = 1;

    public function __construct(public int $runId)
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        $sourceId = SourceRun::query()->whereKey($this->runId)->value('source_id');

        return $sourceId !== null ? 'source-'.$sourceId : 'run-'.$this->runId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function handle(IngestRun $ingest, SourceHealthTracker $health): void
    {
        $claimed = SourceRun::query()
            ->whereKey($this->runId)
            ->where('status', RunStatus::Queued)
            ->update([
                'status' => RunStatus::Running,
                'started_at' => CarbonImmutable::now()->utc(),
                'updated_at' => CarbonImmutable::now()->utc(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $run = SourceRun::query()->findOrFail($this->runId);

        try {
            $ingest->execute($run);
        } catch (HostBusy $busy) {
            SourceRun::query()
                ->whereKey($this->runId)
                ->where('status', RunStatus::Running)
                ->update([
                    'status' => RunStatus::Queued,
                    'started_at' => null,
                    'updated_at' => CarbonImmutable::now()->utc(),
                ]);

            $this->release(max(1, $busy->seconds));
        } catch (Throwable $e) {
            $abandoned = SourceRun::query()
                ->whereKey($this->runId)
                ->where('status', RunStatus::Running)
                ->update([
                    'status' => RunStatus::Failed,
                    'failure_kind' => FetchFailureKind::Abandoned->value,
                    'error_message' => 'The run stopped unexpectedly ('.class_basename($e).').',
                    'finished_at' => CarbonImmutable::now()->utc(),
                    'updated_at' => CarbonImmutable::now()->utc(),
                ]);

            if ($abandoned === 1) {
                // An abandoned live run counts towards health like any failure
                // (SRC-08). A tracker error is reported, never hides $e.
                rescue(fn () => $health->record(SourceRun::query()->findOrFail($this->runId)));
            }

            throw $e;
        }
    }
}
