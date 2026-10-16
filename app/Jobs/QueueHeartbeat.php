<?php

namespace App\Jobs;

use App\Models\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Worker liveness (OPS-04). Dispatched by the scheduler every five minutes;
 * only a worker actually processing it writes the 'queue' heartbeat. Unique
 * for an hour, so a dead worker leaves at most one waiting copy.
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public CarbonImmutable $queuedAt;

    public function __construct(?CarbonImmutable $queuedAt = null)
    {
        $this->queuedAt = ($queuedAt ?? CarbonImmutable::now())->utc();
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE, [
            'queued_at' => $this->queuedAt->toIso8601String(),
            'latency_seconds' => max(0, CarbonImmutable::now()->getTimestamp() - $this->queuedAt->getTimestamp()),
        ]);
    }
}
