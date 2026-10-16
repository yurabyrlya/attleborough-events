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
     * Date-group heading: "Today", "Tomorrow" or "Saturday 31 October",
     * with the year added outside the current London year
     * ("Friday 1 January 2027").
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

        return $day->year === $today->year ? $day->format('l j F') : $day->format('l j F Y');
    }

    /**
     * Short date for titles: "Fri 23 Oct", with the year added outside the
     * current London year ("Fri 1 Jan 2027"), the same rule as heading().
     */
    public static function shortDate(CarbonImmutable $instant, ?CarbonImmutable $now = null): string
    {
        $day = self::local($instant);
        $year = self::local($now ?? CarbonImmutable::now())->year;

        return $day->year === $year ? $day->format('D j M') : $day->format('D j M Y');
    }

    /**
     * Card time:
     *  - timed: "Sat 14 Nov, 7:30pm" / "Sat 14 Nov, 7pm"
     *  - timed, ends on a later day: "Sat 31 Oct, 7pm until Sun 1 Nov"
     *  - all-day: "All day"
     *  - all-day over several days: "All day until Sun 1 Nov"
     */
    public static function cardTime(EventOccurrence $occurrence): string
    {
        $start = self::local($occurrence->starts_at);
        $lastDay = self::lastDay($occurrence);
        $until = $lastDay !== null && ! $lastDay->isSameDay($start)
            ? ' until '.$lastDay->format('D j M')
            : '';

        if ($occurrence->event->all_day) {
            return 'All day'.$until;
        }

        return $start->format('D j M').', '.self::time($start).$until;
    }

    /**
     * The last local calendar day the occurrence covers. All-day ends are
     * exclusive (next local midnight), so step back one second.
     */
    private static function lastDay(EventOccurrence $occurrence): ?CarbonImmutable
    {
        if ($occurrence->ends_at === null) {
            return null;
        }

        $end = self::local($occurrence->ends_at);

        return $occurrence->event->all_day ? $end->subSecond()->startOfDay() : $end->startOfDay();
    }

    /**
     * "7:30pm" / "7pm".
     */
    public static function time(CarbonImmutable $instant): string
    {
        return str_replace(':00', '', self::local($instant)->format('g:ia'));
    }
}
