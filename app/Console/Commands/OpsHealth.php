<?php

namespace App\Console\Commands;

use App\Enums\HealthState;
use App\Ops\HealthReport;
use Illuminate\Console\Command;

/**
 * One line per health check plus a final HEALTHY / UNHEALTHY line (OPS-04).
 * Exit 1 when the scheduler or queue is not OK, calendar-sync is Stale or
 * Failed, or any failed job exists. calendar-sync 'Not run yet' is a warning.
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
