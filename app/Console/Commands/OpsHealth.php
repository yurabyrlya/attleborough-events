<?php

namespace App\Console\Commands;

use App\Enums\HealthState;
use App\Ops\HealthReport;
use Illuminate\Console\Command;

/**
 * One line per health check plus a final HEALTHY / UNHEALTHY line (OPS-04).
 * Exit 1 when the scheduler or queue is not OK, calendar-sync is Stale or
 * Failed, or any failed job exists. calendar-sync 'Not run yet' and an
 * unhealthy sources line (SRC-08) are warnings that never change the exit code.
 */
class OpsHealth extends Command
{
    protected $signature = 'ops:health';

    protected $description = 'Print scheduler / queue / sync / failed-job health; exit 1 when unhealthy';

    public function handle(HealthReport $report): int
    {
        $healthy = true;

        foreach ($report->all() as $check) {
            $line = "{$check->key}: ".strtoupper($check->state->label());

            if ($check->relativeAge() !== null) {
                $line .= " (last seen {$check->relativeAge()})";
            }

            if ($check->state === HealthState::NotRunYet) {
                $line .= ' (warning)';
            } elseif (! $check->state->isHealthy()) {
                $healthy = false;
            }

            $this->line($line);
        }

        // Sources are a warning only: a remote site's failure is not an app
        // outage and must not fail a deploy check (T-03-21).
        $sources = $report->sources();
        $this->line($sources->state->isHealthy()
            ? 'sources: '.strtoupper($sources->state->label())
            : 'sources: '.strtoupper($sources->state->label()).' (warning): '.$sources->detail);

        $failedJobs = $report->failedJobCount();
        $this->line("failed jobs: {$failedJobs}");
        $this->line("jobs waiting: {$report->pendingJobs()}");

        if ($failedJobs > 0) {
            $healthy = false;
        }

        $this->line($healthy ? 'HEALTHY' : 'UNHEALTHY');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
