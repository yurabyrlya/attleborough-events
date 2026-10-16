<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum HealthState: string implements HasColor, HasLabel
{
    case Ok = 'ok';
    case Stale = 'stale';
    case NeverSeen = 'never_seen';
    case Failed = 'failed';
    case NotRunYet = 'not_run_yet';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Stale => 'Stale',
            self::NeverSeen => 'Never seen',
            self::Failed => 'Failed',
            self::NotRunYet => 'Not run yet',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Stale, self::NeverSeen, self::Failed => 'danger',
            self::NotRunYet => 'gray',
        };
    }

    public function getColor(): string
    {
        return $this->color();
    }

    /**
     * Only OK is healthy. NotRunYet is reported by ops:health as a warning.
     */
    public function isHealthy(): bool
    {
        return $this === self::Ok;
    }
}
