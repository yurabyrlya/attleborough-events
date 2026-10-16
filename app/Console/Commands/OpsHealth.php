<?php

namespace App\Console\Commands;

use App\Ops\HealthReport;
use Illuminate\Console\Command;

/**
 * One line per health check plus a final HEALTHY / UNHEALTHY line (OPS-04).
 * Exit 1 when unhealthy, so scripts and the local checks can gate on it.
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

            $this->line($line);

            if (! $check->state->isHealthy()) {
                $healthy = false;
            }
        }

        $this->line($healthy ? 'HEALTHY' : 'UNHEALTHY');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
