<?php

namespace App\Calendar;

use App\Enums\DateFilter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A half-open date window [start, end) in Europe/London (CAL-02).
 *
 * "Sun 23:59" in CAL-02 is represented as an exclusive Mon 00:00 end, so
 * windows tile without gaps or overlaps. All day arithmetic (addDay, next)
 * happens on London-zoned instants, never on UTC and never by adding a fixed
 * 24 hours, so windows are 23h/25h long across the clock changes. Use
 * startUtc()/endUtc() when binding to queries.
 */
final readonly class DateWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function for(DateFilter $filter, CarbonImmutable $now): self
    {
        $local = $now->setTimezone((string) config('calendar.timezone', 'Europe/London'));
        $today = $local->startOfDay();

        return match ($filter) {
            DateFilter::Today => new self($today, $today->addDay()),
            DateFilter::Weekend => self::weekend($local, $today),
            DateFilter::Week => new self($today, $today->next(CarbonInterface::MONDAY)),
        };
    }

    /**
     * The upcoming weekend, or the current one when today is Saturday or Sunday.
     */
    private static function weekend(CarbonImmutable $local, CarbonImmutable $today): self
    {
        $saturday = match (true) {
            $local->isSaturday() => $today,
            $local->isSunday() => $today->subDay(),
            default => $today->next(CarbonInterface::SATURDAY),
        };

        return new self($saturday, $saturday->addDays(2));
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->end->utc();
    }
}
