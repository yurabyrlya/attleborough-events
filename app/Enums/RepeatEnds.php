<?php

namespace App\Enums;

/**
 * How a repeating series ends (form state; stored as RRULE UNTIL / COUNT).
 */
enum RepeatEnds: string
{
    case Never = 'never';
    case OnDate = 'until';
    case AfterCount = 'count';
}
