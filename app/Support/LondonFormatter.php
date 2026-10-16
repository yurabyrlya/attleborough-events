<?php

namespace App\Support;

use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;

/**
 * UK-style display strings in Europe/London (D-05).
 */
final class LondonFormatter
{
    public const TZ = 'Europe/London';

    public static function local(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone(self::TZ);
    }

    /**
     * Date-group heading: "Today", "Tomorrow" or "Saturday 31 October".
     */
    public static function heading(CarbonImmutable $date, ?CarbonImmutable $now = null): string
    {
        $day = self::local($date)->startOfDay();
        $today = self::local($now ?? CarbonImmutable::now())->startOfDay();

        if ($day->equalTo($today)) {
            return 'Today';
        }

        if ($day->equalTo($today->addDay())) {
            return 'Tomorrow';
        }

        return $day->format('l j F');
    }

    /**
     * Card time, e.g. "Sat 14 Nov, 7:30pm" or "Sat 14 Nov, 7pm".
     */
    public static function cardTime(EventOccurrence $occurrence): string
    {
        $start = self::local($occurrence->starts_at);

        return $start->format('D j M').', '.self::time($start);
    }

    /**
     * "7:30pm" / "7pm".
     */
    public static function time(CarbonImmutable $instant): string
    {
        return str_replace(':00', '', self::local($instant)->format('g:ia'));
    }
}
