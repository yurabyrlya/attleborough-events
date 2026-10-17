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
     *  - timed, ends at midnight: "Sat 31 Oct, 7pm"
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
     * Like cardTime() but always names the day, for places with no date
     * heading above them (event page dates list, add-to-calendar link
     * names, share text): all-day becomes "Sat 7 Nov, all day" /
     * "Sat 7 Nov, all day until Sun 8 Nov"; timed is cardTime() unchanged.
     */
    public static function datedTime(EventOccurrence $occurrence): string
    {
        if (! $occurrence->event->all_day) {
            return self::cardTime($occurrence);
        }

        return self::local($occurrence->starts_at)->format('D j M').', '.lcfirst(self::cardTime($occurrence));
    }

    /**
     * The last local calendar day the occurrence covers. An end exactly at
     * local midnight is exclusive, so step back one second: always for
     * all-day ends (next local midnight), and for timed ends at 00:00:00
     * London (WR-05: 19:00-00:00 is one evening, not two days). A timed end
     * after midnight (00:30) still counts the next day.
     */
    private static function lastDay(EventOccurrence $occurrence): ?CarbonImmutable
    {
        if ($occurrence->ends_at === null) {
            return null;
        }

        $end = self::local($occurrence->ends_at);
        $exclusive = $occurrence->event->all_day || $end->equalTo($end->startOfDay());

        return $exclusive ? $end->subSecond()->startOfDay() : $end->startOfDay();
    }

    /**
     * A stored LOCAL wall-clock schedule (drafts, events) as UK text, never
     * converted: "Sat 24 Oct, 7:30pm", "Sat 24 Oct, all day",
     * "Sat 24 Oct, time missing"; the year is added outside the current
     * London year, as in shortDate(). Null date gives "Date missing".
     */
    public static function wallClock(?CarbonImmutable $date, ?string $time, bool $allDay, ?CarbonImmutable $now = null): string
    {
        if ($date === null) {
            return 'Date missing';
        }

        $local = CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), self::TZ);

        if (! $local instanceof CarbonImmutable) {
            return 'Date missing';
        }

        $year = self::local($now ?? CarbonImmutable::now())->year;
        $day = $local->year === $year ? $local->format('D j M') : $local->format('D j M Y');

        if ($allDay) {
            return $day.', all day';
        }

        $clock = $time === null ? false : CarbonImmutable::createFromFormat('!H:i:s', strlen($time) === 5 ? $time.':00' : $time, self::TZ);

        if (! $clock instanceof CarbonImmutable) {
            return $day.', time missing';
        }

        return $day.', '.str_replace(':00', '', $clock->format('g:ia'));
    }

    /**
     * "7:30pm" / "7pm".
     */
    public static function time(CarbonImmutable $instant): string
    {
        return str_replace(':00', '', self::local($instant)->format('g:ia'));
    }
}
