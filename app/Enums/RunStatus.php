<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a source run is. Moves queued → running → one terminal state
 * (succeeded, not_modified, failed, blocked); terminal states are final.
 * Values match source_runs_status_check. Queued and running runs occupy the
 * source_runs_one_active index (SRC-04).
 */
enum RunStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case NotModified = 'not_modified';
    case Failed = 'failed';
    case Blocked = 'blocked';

    /**
     * True while the run holds the source's one active slot.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Running => 'Running',
            self::Succeeded => 'Succeeded',
            self::NotModified => 'Not modified',
            self::Failed => 'Failed',
            self::Blocked => 'Blocked by robots.txt',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Running => 'info',
            self::Succeeded, self::NotModified => 'success',
            self::Failed => 'danger',
            self::Blocked => 'warning',
        };
    }
}
