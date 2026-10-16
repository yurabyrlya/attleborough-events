<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OccurrenceStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Hidden = 'hidden';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Cancelled => 'Cancelled',
            self::Hidden => 'Hidden',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'success',
            self::Cancelled => 'danger',
            self::Hidden => 'gray',
        };
    }
}
