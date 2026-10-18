<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a source item stands in its feed: listed (active), marked
 * STATUS:CANCELLED by the source, or gone from complete runs (missing,
 * PIPE-08). Values match source_items_status_check.
 */
enum SourceItemStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Missing = 'missing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Listed',
            self::Cancelled => 'Cancelled by the source',
            self::Missing => 'No longer listed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Cancelled => 'danger',
            self::Missing => 'warning',
        };
    }
}
