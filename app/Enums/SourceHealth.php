<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A source's health (SRC-08), moved only by finished live runs
 * (SourceHealthTracker): degraded after config('ingest.health.degraded_after')
 * consecutive failures, unhealthy after unhealthy_after. The scheduler skips
 * an unhealthy source; "Run now" still works and one success makes it
 * healthy again. HealthState stays the ops-line enum (conflict C17).
 */
enum SourceHealth: string implements HasColor, HasLabel
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';

    /**
     * The health for this many consecutive failed live runs.
     */
    public static function forFailures(int $consecutiveFailures): self
    {
        return match (true) {
            $consecutiveFailures >= (int) config('ingest.health.unhealthy_after', 5) => self::Unhealthy,
            $consecutiveFailures >= (int) config('ingest.health.degraded_after', 3) => self::Degraded,
            default => self::Healthy,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Unhealthy => 'Unhealthy (paused)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Degraded => 'warning',
            self::Unhealthy => 'danger',
        };
    }
}
