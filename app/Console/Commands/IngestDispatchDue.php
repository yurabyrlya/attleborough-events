<?php

namespace App\Console\Commands;

use App\Enums\FetchFailureKind;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Ingestion\Health\SourceHealthTracker;
use App\Ingestion\RunAlreadyActive;
use App\Ingestion\RunLauncher;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ingestion scheduler (SRC-04, D-02), run every ten minutes.
 *
 * First the janitor: a run still running 15 minutes after it started, or
 * still queued 2 hours after it was queued, is marked failed/abandoned so a
 * killed worker never holds a source's one active slot for ever (T-03-18);
 * each abandoned live run counts towards the source's health (SRC-08).
 * Then every active, not unhealthy source whose next_check_at is unset or
 * at or before now gets a scheduled run, in next_check_at order then id.
 * A source that already has an active run is skipped but still moves to its
 * next slot. One failing source is reported and skipped; the exit code is 1
 * when any failed. Logs carry ids only.
 */
class IngestDispatchDue extends Command
{
    public const int RUNNING_TIMEOUT_MINUTES = 15;

    public const int QUEUED_TIMEOUT_MINUTES = 120;

    protected $signature = 'ingest:dispatch-due {--source=* : Only these source ids}';

    protected $description = 'Mark abandoned source runs failed and start a run for every due source';

    public function handle(RunLauncher $launcher, SourceHealthTracker $health): int
    {
        $now = CarbonImmutable::now();
        $abandoned = $this->sweepAbandonedRuns($now, $health);
        $only = array_values(array_filter(array_map('intval', (array) $this->option('source'))));

        $sources = Source::query()
            ->where('is_active', true)
            ->where('health', '<>', SourceHealth::Unhealthy->value)
            ->where(fn ($query) => $query
                ->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', $now->utc()))
            ->when($only !== [], fn ($query) => $query->whereKey($only))
            ->orderByRaw('next_check_at asc nulls first')
            ->orderBy('id')
            ->get();

        $started = 0;
        $alreadyActive = 0;
        $failed = [];

        foreach ($sources as $source) {
            try {
                try {
                    $launcher->runNow($source, RunTrigger::Schedule);
                    $started++;
                } catch (RunAlreadyActive) {
                    // Someone (Run now, an earlier dispatch) is checking it already.
                    $alreadyActive++;
                }

                $source->forceFill([
                    'next_check_at' => $launcher->nextCheckAt($source, $now->setTimezone('Europe/London'))->utc(),
                ])->save();
            } catch (Throwable $e) {
                report($e);
                // Ids and exception class only: never source content (T-01-22 pattern).
                Log::error('ingest:dispatch-due failed', ['source_id' => $source->id, 'error' => class_basename($e)]);
                $failed[] = $source->id;
            }
        }

        $this->info("Started {$started} runs, {$alreadyActive} already active, ".count($failed)." failed; marked {$abandoned} abandoned.");

        if ($failed !== []) {
            $this->error('Failed source ids: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Each stale run is marked with its own guarded UPDATE (status and age
     * re-checked, so a run that finished meanwhile is left alone) and then
     * recorded with SourceHealthTracker: an abandoned live run counts as a
     * failure (SRC-08); previews change nothing.
     *
     * @return int how many runs were marked abandoned
     */
    private function sweepAbandonedRuns(CarbonImmutable $now, SourceHealthTracker $health): int
    {
        $nowUtc = $now->utc();
        $sweeps = [
            [RunStatus::Running, 'started_at', $nowUtc->subMinutes(self::RUNNING_TIMEOUT_MINUTES), 'The run stopped responding and was marked abandoned.'],
            [RunStatus::Queued, 'queued_at', $nowUtc->subMinutes(self::QUEUED_TIMEOUT_MINUTES), 'The run waited in the queue too long and was marked abandoned.'],
        ];
        $abandoned = 0;

        foreach ($sweeps as [$status, $column, $cutoff, $message]) {
            $stale = fn () => SourceRun::query()->where('status', $status)->where($column, '<', $cutoff);

            foreach ($stale()->orderBy('id')->pluck('id') as $runId) {
                if ($stale()->whereKey($runId)->update($this->abandonedColumns($nowUtc, $message)) !== 1) {
                    continue;
                }

                $abandoned++;
                $health->record(SourceRun::query()->findOrFail($runId));
            }
        }

        return $abandoned;
    }

    /**
     * @return array<string, mixed>
     */
    private function abandonedColumns(CarbonImmutable $nowUtc, string $message): array
    {
        return [
            'status' => RunStatus::Failed,
            'failure_kind' => FetchFailureKind::Abandoned->value,
            'error_message' => $message,
            'finished_at' => $nowUtc,
            'updated_at' => $nowUtc,
        ];
    }
}
