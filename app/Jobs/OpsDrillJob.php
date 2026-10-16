<?php

namespace App\Jobs;

use App\Models\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * A deliberately failing job for the ops drill (ops:drill). Fails until
 * $failUntil, then succeeds and writes the 'ops-drill' heartbeat. One try,
 * so it lands in failed_jobs at once. The payload is only a timestamp.
 */
class OpsDrillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  string  $failUntil  ISO-8601 UTC instant
     */
    public function __construct(public string $failUntil) {}

    public function handle(): void
    {
        $until = CarbonImmutable::parse($this->failUntil);

        if (CarbonImmutable::now()->lessThan($until)) {
            $london = $until->setTimezone((string) config('calendar.timezone'))->format('H:i:s');

            throw new RuntimeException("Deliberate ops drill failure (succeeds after {$london})");
        }

        Heartbeat::beat(Heartbeat::DRILL, ['failed_until' => $this->failUntil]);
    }
}
