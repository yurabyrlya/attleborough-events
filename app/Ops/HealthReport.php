<?php

namespace App\Ops;

use App\Enums\HealthState;
use App\Models\Heartbeat;
use Carbon\CarbonImmutable;

/**
 * The single source of health states for the System health page, its nav
 * badge and ops:health (OPS-04). Reads the heartbeats table only, never the
 * cache store.
 */
class HealthReport
{
    public function scheduler(): HealthCheck
    {
        return $this->freshness(
            key: 'scheduler',
            title: 'Scheduler',
            heartbeat: Heartbeat::find(Heartbeat::SCHEDULER),
            staleAfter: (int) config('ops.scheduler_stale_after'),
            expectation: 'Expected every minute; stale after 3 minutes',
        );
    }

    /**
     * @return list<HealthCheck>
     */
    public function all(): array
    {
        return [$this->scheduler()];
    }

    /**
     * Age of a heartbeat in whole seconds. A future timestamp (clock step)
     * counts as 0, never negative.
     */
    private function ageOf(CarbonImmutable $lastSeenAt): int
    {
        return max(0, CarbonImmutable::now()->getTimestamp() - $lastSeenAt->getTimestamp());
    }

    private function freshness(string $key, string $title, ?Heartbeat $heartbeat, int $staleAfter, string $expectation, ?string $detail = null): HealthCheck
    {
        if ($heartbeat === null) {
            return new HealthCheck($key, $title, HealthState::NeverSeen, null, null, $expectation, $detail);
        }

        $age = $this->ageOf($heartbeat->last_seen_at);

        return new HealthCheck(
            $key,
            $title,
            $age > $staleAfter ? HealthState::Stale : HealthState::Ok,
            $heartbeat->last_seen_at,
            $age,
            $expectation,
            $detail,
        );
    }
}
