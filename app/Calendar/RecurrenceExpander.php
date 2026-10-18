<?php

namespace App\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;

/**
 * The ONLY recurrence expansion engine (sabre/vobject 5).
 *
 * Builds a synthetic VEVENT (DTSTART;TZID=Europe/London, or DTSTART;VALUE=DATE
 * for all-day series) carrying the stored RRULE and one EXDATE per
 * events.excluded_dates entry (D-19, same TZID / VALUE=DATE form as
 * DTSTART), then walks sabre's EventIterator in the event's timezone.
 * Returns the London local dates (Y-m-d, the recurrence keys) of instances
 * that START inside [windowStart, windowEnd).
 *
 * Reads only timezone, all_day, start_date, start_time, recurrence_rule and
 * excluded_dates, so it also works on unsaved Event models (Filament "Next
 * dates" preview).
 */
final class RecurrenceExpander
{
    /** Guard against runaway rules (T-01-18). */
    public const MAX_INSTANCES = 500;

    /**
     * @return list<string>
     */
    public function instanceDates(Event $event, CarbonImmutable $windowStartLocal, CarbonImmutable $windowEndLocal): array
    {
        if ($event->recurrence_rule === null || trim($event->recurrence_rule) === '') {
            return [];
        }

        $tz = new DateTimeZone($event->timezone ?: (string) config('calendar.timezone', 'Europe/London'));
        $windowStart = $windowStartLocal->setTimezone($tz);
        $windowEnd = $windowEndLocal->setTimezone($tz);

        try {
            $uid = 'event-'.($event->id ?? 'preview').'@'.config('site.domain');
            $calendar = new VCalendar;
            $dtStart = $this->describe($calendar->add('VEVENT', ['UID' => $uid]), $event, $tz);

            // sabre always yields DTSTART itself, even when UNTIL precedes it.
            if ($this->untilPrecedes($event->recurrence_rule, $dtStart, $tz)) {
                return [];
            }

            // ALWAYS pass the timezone: sabre defaults to UTC.
            $iterator = new EventIterator($calendar, $uid, $tz);

            // fastForward() skips instances whose END <= the given instant. A
            // timed VEVENT without DTEND has zero duration, so step back one
            // second to keep an instance that starts exactly at window start.
            $iterator->fastForward($windowStart->subSecond());

            $dates = [];

            while ($iterator->valid() && $iterator->getDtStart() < $windowEnd) {
                $start = $iterator->getDtStart();

                if ($start >= $windowStart) {
                    if (count($dates) >= self::MAX_INSTANCES) {
                        throw new RuntimeException("Recurrence for event [{$event->id}] yields more than ".self::MAX_INSTANCES.' instances in the window.');
                    }

                    $dates[] = $start->format('Y-m-d');
                }

                $iterator->next();
            }
        } catch (NoInstancesException) {
            return [];
        }

        return $dates;
    }

    /**
     * Adds DTSTART (TZID or VALUE=DATE) and RRULE to the synthetic VEVENT. No
     * DTEND: the window filter does not need it.
     */
    private function describe(mixed $vevent, Event $event, DateTimeZone $tz): DateTimeImmutable
    {
        if (! $vevent instanceof Component) {
            throw new RuntimeException('Could not build the synthetic VEVENT.');
        }

        $date = $event->start_date->format('Y-m-d');

        if ($event->all_day) {
            $dtStart = $this->localDateTime($date, '00:00:00', $tz);
            $vevent->add('DTSTART', $dtStart, ['VALUE' => 'DATE']);
        } else {
            if ($event->start_time === null) {
                throw new InvalidArgumentException("Event [{$event->id}] has no start time.");
            }

            $dtStart = $this->localDateTime($date, $event->start_time, $tz);
            $vevent->add('DTSTART', $dtStart);
        }

        $vevent->add('RRULE', $event->recurrence_rule);

        // D-19: an excluded London date at the series' own start time.
        foreach (self::excludedDates($event) as $excluded) {
            if ($event->all_day) {
                $vevent->add('EXDATE', $this->localDateTime($excluded, '00:00:00', $tz), ['VALUE' => 'DATE']);
            } else {
                $vevent->add('EXDATE', $this->localDateTime($excluded, (string) $event->start_time, $tz));
            }
        }

        return $dtStart;
    }

    /**
     * The series in words, with the dates it skips (D-19), e.g. "Weekly on
     * Wednesday, except Wed 21 Oct". Null for a one-off. A rule the form
     * cannot represent reads "Repeats".
     */
    public function summary(Event $event): ?string
    {
        if ($event->recurrence_rule === null || trim($event->recurrence_rule) === '') {
            return null;
        }

        $tz = $event->timezone ?: (string) config('calendar.timezone', 'Europe/London');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $event->start_date->format('Y-m-d'), $tz);

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        $label = ManualRecurrence::tryFromStoredRule($event->recurrence_rule, $start, $event->all_day)?->label($start) ?? 'Repeats';

        $excluded = array_map(function (string $date) use ($start, $tz): string {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz);

            if (! $day instanceof CarbonImmutable) {
                return $date;
            }

            return $day->format($day->year === $start->year ? 'D j M' : 'D j M Y');
        }, self::excludedDates($event));

        if ($excluded === []) {
            return $label;
        }

        $last = array_pop($excluded);
        $list = $excluded === [] ? $last : implode(', ', $excluded).' and '.$last;

        return "{$label}, except {$list}";
    }

    /**
     * events.excluded_dates in Y-m-d shape, unique and sorted.
     *
     * @return list<string>
     */
    private static function excludedDates(Event $event): array
    {
        // getAttribute: a model loaded without the column (or a preview
        // model) simply has no exclusions.
        $stored = $event->getAttribute('excluded_dates');

        if (! is_array($stored)) {
            return [];
        }

        $dates = array_values(array_unique(preg_grep('/^\d{4}-\d{2}-\d{2}$/', array_filter($stored, is_string(...)))));
        sort($dates);

        return $dates;
    }

    private function untilPrecedes(string $rule, DateTimeImmutable $dtStart, DateTimeZone $tz): bool
    {
        if (preg_match('/(?:^|;)UNTIL=([0-9TZ]+)/', $rule, $m) !== 1) {
            return false;
        }

        return DateTimeParser::parse($m[1], $tz) < $dtStart;
    }

    private function localDateTime(string $date, string $time, DateTimeZone $tz): DateTimeImmutable
    {
        $time = strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$date} {$time}", $tz);

        if ($value === false) {
            throw new InvalidArgumentException("Invalid local date/time [{$date} {$time}].");
        }

        return $value;
    }
}
