<?php

namespace App\Enums;

/**
 * Public date chips (CAL-02). The values are a public URL contract
 * (/?when=today|weekend|week). "All upcoming" is the absence of a filter.
 */
enum DateFilter: string
{
    case Today = 'today';
    case Weekend = 'weekend';
    case Week = 'week';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Weekend => 'This weekend',
            self::Week => 'This week',
        };
    }
}
