<?php

namespace App\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns an event's local wall-clock schedule into UTC instants for one
 * instance date.
 *
 * All arithmetic happens on CarbonImmutable values zoned in the event's
 * timezone (Europe/London); results are converted with ->utc() at the end.
 *
 * Rules (REC-02):
 *  - Timed with end: ends at end_time on (end_date ?? instance date). An end
 *    that is not after the start rolls over to the next day (overnight).
 *  - Timed without end: ends_at is null; expires `calendar.no_end_expiry_hours`
 *    (3) absolute hours after the start. Exception: a timed event whose
 *    end_date is after its start date but has no end_time runs to the end of
 *    that last day (exclusive next local midnight).
 *  - All-day (single or multi-day): ONE occurrence from local 00:00 on the
 *    first day to local 00:00 on the day AFTER the last day.
 *
 * Exclusive-end convention: an all-day ends_at is the first instant that is
 * NOT part of the event (next local midnight), never 23:59:59. A Sat-Sun
 * event therefore ends at Monday 00:00 Europe/London. DST days are 23h/25h
 * long because the arithmetic is done on London wall-clock dates.
 *
 *  - expires_at = ends_at whenever there is an end.
 *
 * Recurring series (recurrence_rule set) are single-day: end_date is ignored
 * and every computation is anchored on the INSTANCE date. A timed instance
 * ends on the instance date (or the next day when end_time <= start_time);
 * an all-day instance ends at the next local midnight. A nonexistent local
 * time (e.g. 01:30 on 28 Mar 2027) is shifted forward by PHP (02:30 BST),
 * matching sabre/vobject.
 */
final class EventTiming
{
    public function __construct(private ?int $noEndExpiryHours = null) {}

    public function forDate(Event $event, CarbonImmutable|string $instanceDate): OccurrenceTiming
    {
        $tz = $event->timezone ?: (string) config('calendar.timezone', 'Europe/London');
        $date = $instanceDate instanceof CarbonImmutable ? $instanceDate->format('Y-m-d') : $instanceDate;

        if ($event->all_day) {
            return $this->allDay($event, $date, $tz);
        }

        if ($event->start_time === null) {
            throw new InvalidArgumentException("Event [{$event->id}] has no start time.");
        }

        $startsAt = $this->localDateTime($date, $event->start_time, $tz);
        $endsAt = null;
        $endDate = $this->endDate($event, $date, $tz);

        if ($event->end_time !== null) {
            $endsAt = $this->localDateTime($endDate, $event->end_time, $tz);

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                $endsAt = $endsAt->addDay(); // overnight: same wall-clock time on the next local day
            }
        } elseif ($endDate !== $date) {
            $endsAt = $this->localMidnight($endDate, $tz)->addDay();
        }

        $expiresAt = $endsAt ?? $startsAt->utc()->addHours($this->noEndExpiryHours());

        return new OccurrenceTiming(
            key: $date,
            startsAt: $startsAt->utc(),
            endsAt: $endsAt?->utc(),
            expiresAt: $expiresAt->utc(),
        );
    }

    private function allDay(Event $event, string $date, string $tz): OccurrenceTiming
    {
        $startsAt = $this->localMidnight($date, $tz);
        $endsAt = $this->localMidnight($this->endDate($event, $date, $tz), $tz)->addDay();

        return new OccurrenceTiming(
            key: $date,
            startsAt: $startsAt->utc(),
            endsAt: $endsAt->utc(),
            expiresAt: $endsAt->utc(),
        );
    }

    private function localMidnight(string $date, string $tz): CarbonImmutable
    {
        return $this->localDateTime($date, '00:00:00', $tz);
    }

    /**
     * The local end date for an instance: keeps the event's start→end day
     * offset relative to the instance date.
     */
    private function endDate(Event $event, string $instanceDate, string $tz): string
    {
        if ($event->end_date === null || $event->recurrence_rule !== null) {
            return $instanceDate; // recurring instances never span several days
        }

        $spanDays = (int) $event->start_date->diffInDays($event->end_date);

        return $this->localMidnight($instanceDate, $tz)
            ->addDays(max(0, $spanDays))
            ->format('Y-m-d');
    }

    private function localDateTime(string $date, string $time, string $tz): CarbonImmutable
    {
        $time = strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);

        $value = CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$date} {$time}", $tz);

        if (! $value instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Invalid local date/time [{$date} {$time}].");
        }

        return $value;
    }

    private function noEndExpiryHours(): int
    {
        return $this->noEndExpiryHours ?? (int) config('calendar.no_end_expiry_hours', 3);
    }
}
