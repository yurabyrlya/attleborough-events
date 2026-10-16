<?php

namespace App\Enums;

/**
 * The simple recurrence patterns the admin can pick in Filament (ADM-02).
 *
 * Stored on events as an RRULE (see App\Calendar\ManualRecurrence), never as
 * this enum: the enum is form state only.
 */
enum RepeatPattern: string
{
    case None = 'none';
    case Weekly = 'weekly';
    case MonthlyNth = 'monthly_nth';
    case MonthlyLast = 'monthly_last';
}
