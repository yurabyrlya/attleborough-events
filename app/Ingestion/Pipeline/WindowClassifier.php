<?php

namespace App\Ingestion\Pipeline;

use App\Calendar\EventTiming;
use App\Calendar\OccurrenceTiming;
use App\Calendar\RecurrenceExpander;
use App\Ingestion\Data\NormalisedFacts;
use App\Models\Event;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sorts an item into past / in_window / far_future (PIPE-01; counting
 * happens in the run, 03-05).
 *
 * The import window is calendar.horizon_days ahead of now (90, D-20): an
 * instance starting at or after now + horizon_days (Europe/London) is far in
 * the future. An item is past when every instance's REC-02 expiry is at or
 * before now. Expiry uses the published rules exactly (EventTiming: end, or
 * start + calendar.no_end_expiry_hours, or the next local midnight after an
 * all-day event's last day), on an unsaved Event built from the facts.
 * Series dates come from RecurrenceExpander (sabre EventIterator) with the
 * item's excluded dates removed.
 *
 * Pure: the unsaved Event is never written.
 */
final class WindowClassifier
{
    public const string PAST = 'past';

    public const string IN_WINDOW = 'in_window';

    public const string FAR_FUTURE = 'far_future';

    /** How far past the window a series is checked for later dates. */
    private const int LOOKAHEAD_YEARS = 5;

    public function __construct(
        private readonly RecurrenceExpander $expander,
        private readonly EventTiming $timing,
    ) {}

    /**
     * @return self::PAST|self::IN_WINDOW|self::FAR_FUTURE
     */
    public function classify(NormalisedFacts $facts, CarbonImmutable $nowLondon): string
    {
        $tz = (string) config('calendar.timezone', 'Europe/London');
        $now = $nowLondon->setTimezone($tz);
        $windowEnd = $now->addDays((int) config('calendar.horizon_days', 90));
        $event = $this->event($facts, $tz);

        if ($facts->rrule === null) {
            return $this->instanceClass($this->timing->forDate($event, $facts->startDate), $now, $windowEnd);
        }

        try {
            // Two days back covers any instance that started earlier but is
            // still running (overnight, all-day).
            foreach ($this->dates($event, $facts, $now->subDays(2)->startOfDay(), $windowEnd) as $date) {
                $timing = $this->timing->forDate($event, $date);

                if ($timing->expiresAt->greaterThan($now)) {
                    return $this->instanceClass($timing, $now, $windowEnd);
                }
            }
        } catch (RuntimeException|InvalidArgumentException) {
            // A rule too dense to expand (D-19 parse error anyway): keep it
            // in front of the admin rather than skip it.
            return self::IN_WINDOW;
        }

        try {
            $later = $this->dates($event, $facts, $windowEnd, $windowEnd->addYears(self::LOOKAHEAD_YEARS));
        } catch (RuntimeException|InvalidArgumentException) {
            return self::FAR_FUTURE;
        }

        return $later !== [] ? self::FAR_FUTURE : self::PAST;
    }

    private function instanceClass(OccurrenceTiming $timing, CarbonImmutable $now, CarbonImmutable $windowEnd): string
    {
        if ($timing->expiresAt->lessThanOrEqualTo($now)) {
            return self::PAST;
        }

        return $timing->startsAt->greaterThanOrEqualTo($windowEnd) ? self::FAR_FUTURE : self::IN_WINDOW;
    }

    /**
     * @return list<string>
     */
    private function dates(Event $event, NormalisedFacts $facts, CarbonImmutable $from, CarbonImmutable $until): array
    {
        return array_values(array_diff($this->expander->instanceDates($event, $from, $until), $facts->excludedDates));
    }

    private function event(NormalisedFacts $facts, string $tz): Event
    {
        return (new Event)->forceFill([
            'timezone' => $tz,
            'all_day' => $facts->allDay,
            'start_date' => $facts->startDate,
            'start_time' => $facts->startTime,
            'end_date' => $facts->endDate,
            'end_time' => $facts->endTime,
            'recurrence_rule' => $facts->rrule,
        ]);
    }
}
