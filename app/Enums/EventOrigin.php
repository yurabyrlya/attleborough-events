<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventOrigin: string implements HasLabel
{
    case Manual = 'manual';
    case Submission = 'submission';
    case Discovered = 'discovered';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual entry',
            self::Submission => 'Public submission',
            self::Discovered => 'Discovered',
        };
    }
}
