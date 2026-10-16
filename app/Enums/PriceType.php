<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PriceType: string implements HasLabel
{
    case Free = 'free';
    case Paid = 'paid';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Paid => 'Paid',
            self::Unknown => 'Not known',
        };
    }
}
