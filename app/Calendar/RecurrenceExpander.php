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
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;

/**
 * The ONLY recurrence expansion engine (sabre/vobject 5).
 *
 * Builds a synthetic VEVENT (DTSTART;TZID=Europe/London, or DTSTART;VALUE=DATE
 * for all-day series) carrying the stored RRULE, then walks sabre's
 * EventIterator in the event's timezone. Returns the London local dates
 * (Y-m-d, the recurrence keys) of instances that START inside
 * [windowStart, windowEnd).
 *
 * Reads only timezone, all_day, start_date, start_time and recurrence_rule,
 * so it also works on unsaved Event models (Filament "Next dates" preview).
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
            $this->describe($calendar->add('VEVENT', ['UID' => $uid]), $event, $tz);

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
    private function describe(mixed $vevent, Event $event, DateTimeZone $tz): void
    {
        if (! $vevent instanceof Component) {
            throw new RuntimeException('Could not build the synthetic VEVENT.');
        }

        $date = $event->start_date->format('Y-m-d');

        if ($event->all_day) {
            $vevent->add('DTSTART', $this->localDateTime($date, '00:00:00', $tz), ['VALUE' => 'DATE']);
        } else {
            if ($event->start_time === null) {
                throw new InvalidArgumentException("Event [{$event->id}] has no start time.");
            }

            $vevent->add('DTSTART', $this->localDateTime($date, $event->start_time, $tz));
        }

        $vevent->add('RRULE', $event->recurrence_rule);
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
