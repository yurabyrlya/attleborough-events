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
 *    (3) absolute hours after the start.
 *  - expires_at = ends_at when there is an end.
 */
final class EventTiming
{
    public function __construct(private ?int $noEndExpiryHours = null) {}

    public function forDate(Event $event, CarbonImmutable|string $instanceDate): OccurrenceTiming
    {
        $tz = $event->timezone ?: (string) config('calendar.timezone', 'Europe/London');
        $date = $instanceDate instanceof CarbonImmutable ? $instanceDate->format('Y-m-d') : $instanceDate;

        if ($event->start_time === null) {
            throw new InvalidArgumentException("Event [{$event->id}] has no start time.");
        }

        $startsAt = $this->localDateTime($date, $event->start_time, $tz);
        $endsAt = null;

        if ($event->end_time !== null) {
            $endsAt = $this->localDateTime($this->endDate($event, $date, $tz), $event->end_time, $tz);

            if ($endsAt->lessThanOrEqualTo($startsAt)) {
                $endsAt = $endsAt->addDay(); // overnight: same wall-clock time on the next local day
            }
        }

        $expiresAt = $endsAt ?? $startsAt->utc()->addHours($this->noEndExpiryHours());

        return new OccurrenceTiming(
            key: $date,
            startsAt: $startsAt->utc(),
            endsAt: $endsAt?->utc(),
            expiresAt: $expiresAt->utc(),
        );
    }

    /**
     * The local end date for an instance: keeps the event's start→end day
     * offset relative to the instance date.
     */
    private function endDate(Event $event, string $instanceDate, string $tz): string
    {
        if ($event->end_date === null) {
            return $instanceDate;
        }

        $spanDays = (int) $event->start_date->diffInDays($event->end_date);

        return CarbonImmutable::createFromFormat('!Y-m-d', $instanceDate, $tz)
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
