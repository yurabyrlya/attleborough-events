<?php

namespace App\Calendar;

use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Form state <-> RRULE for the admin's simple recurrence choices.
 *
 * The admin never types an RRULE: it is built only here, from the enums and a
 * validated date/count, and fromRrule() rejects any shape this class does not
 * emit (T-01-17).
 *
 * Stored format: a bare RFC 5545 RRULE value without the "RRULE:" prefix.
 *  - Weekly:        FREQ=WEEKLY;BYDAY=FR
 *  - Monthly nth:   FREQ=MONTHLY;BYDAY=2TU
 *  - Monthly last:  FREQ=MONTHLY;BYDAY=-1FR
 *  - Ends on date:  ;UNTIL=20270331T225959Z (timed: London 23:59:59 in UTC,
 *                   because DTSTART has a TZID) or ;UNTIL=20261227 (all-day:
 *                   a DATE, because DTSTART is a DATE). Inclusive.
 *  - Ends after N:  ;COUNT=6 (includes the first date).
 */
final readonly class ManualRecurrence
{
    public const MAX_COUNT = 200;

    private const TIMEZONE = 'Europe/London';

    private const DAY_CODES = [
        'Monday' => 'MO',
        'Tuesday' => 'TU',
        'Wednesday' => 'WE',
        'Thursday' => 'TH',
        'Friday' => 'FR',
        'Saturday' => 'SA',
        'Sunday' => 'SU',
    ];

    private const ORDINALS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];

    private const RRULE_PATTERN = '/^FREQ=(?<freq>WEEKLY|MONTHLY);BYDAY=(?<nth>-1|[1-4])?(?<day>MO|TU|WE|TH|FR|SA|SU)'
        .'(?:;UNTIL=(?<until>\d{8}(?:T\d{6}Z)?)|;COUNT=(?<count>\d{1,3}))?$/';

    public function __construct(
        public RepeatPattern $pattern = RepeatPattern::None,
        public RepeatEnds $ends = RepeatEnds::Never,
        public ?string $untilDate = null,
        public ?int $count = null,
    ) {
        if ($pattern === RepeatPattern::None) {
            return;
        }

        // Round-trip checked so '2026-02-30' or '2026-13-45' is refused rather
        // than overflowing into a later date (WR-03).
        if ($ends === RepeatEnds::OnDate && ($untilDate === null || self::localDate($untilDate) === null)) {
            throw new InvalidArgumentException('A series that ends on a date needs that date (a real Y-m-d date).');
        }

        if ($ends === RepeatEnds::AfterCount && ($count === null || $count < 1 || $count > self::MAX_COUNT)) {
            throw new InvalidArgumentException('A series that ends after a number of times needs a number from 1 to '.self::MAX_COUNT.'.');
        }
    }

    /**
     * @param  CarbonImmutable  $startLocal  The series' first date, in Europe/London
     */
    public function toRrule(CarbonImmutable $startLocal, bool $allDay): ?string
    {
        $day = self::DAY_CODES[$startLocal->englishDayOfWeek];

        $rule = match ($this->pattern) {
            RepeatPattern::None => null,
            RepeatPattern::Weekly => "FREQ=WEEKLY;BYDAY={$day}",
            RepeatPattern::MonthlyNth => 'FREQ=MONTHLY;BYDAY='.self::nth($startLocal).$day,
            RepeatPattern::MonthlyLast => "FREQ=MONTHLY;BYDAY=-1{$day}",
        };

        if ($rule === null) {
            return null;
        }

        return match ($this->ends) {
            RepeatEnds::Never => $rule,
            RepeatEnds::OnDate => $rule.';UNTIL='.$this->untilValue($allDay),
            RepeatEnds::AfterCount => $rule.";COUNT={$this->count}",
        };
    }

    public static function fromRrule(?string $rrule, bool $allDay): self
    {
        if ($rrule === null || trim($rrule) === '') {
            return new self;
        }

        if (preg_match(self::RRULE_PATTERN, $rrule, $m) !== 1) {
            throw new InvalidArgumentException("Unsupported recurrence rule [{$rrule}].");
        }

        $nth = $m['nth'];
        $pattern = match (true) {
            $m['freq'] === 'WEEKLY' && $nth === '' => RepeatPattern::Weekly,
            $m['freq'] === 'MONTHLY' && $nth === '-1' => RepeatPattern::MonthlyLast,
            $m['freq'] === 'MONTHLY' && $nth !== '' => RepeatPattern::MonthlyNth,
            default => throw new InvalidArgumentException("Unsupported recurrence rule [{$rrule}]."),
        };

        $until = $m['until'] ?? '';
        $count = $m['count'] ?? '';

        if ($until !== '') {
            return new self($pattern, RepeatEnds::OnDate, untilDate: self::untilToLocalDate($until));
        }

        if ($count !== '') {
            return new self($pattern, RepeatEnds::AfterCount, count: (int) $count);
        }

        return new self($pattern);
    }

    /**
     * fromRrule() for display paths: null when the stored rule is a shape
     * this class cannot represent (another FREQ, COUNT above 999, an
     * impossible UNTIL), so an edit page can fall back instead of failing
     * (WR-09). fromRrule() stays strict for every other caller.
     */
    public static function tryFromRrule(?string $rrule, bool $allDay): ?self
    {
        try {
            return self::fromRrule($rrule, $allDay);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The recurrence for a stored rule, or null when the form cannot edit it.
     *
     * A rule is editable only if rebuilding it for this start date gives
     * exactly the stored rule (WR-10); anything else would be rewritten by
     * the next save (e.g. BYDAY=MO on a Friday start becomes BYDAY=FR) and
     * sync would delete the old dates. A null rule is "Does not repeat".
     *
     * @param  CarbonImmutable  $startLocal  The series' first date, in Europe/London
     */
    public static function tryFromStoredRule(?string $rrule, CarbonImmutable $startLocal, bool $allDay): ?self
    {
        if ($rrule === null) {
            return new self;
        }

        $parsed = self::tryFromRrule($rrule, $allDay);

        if ($parsed === null || ! $parsed->isValidFor($startLocal)) {
            return null;
        }

        try {
            $rebuilt = $parsed->toRrule($startLocal, $allDay);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $rebuilt === $rrule ? $parsed : null;
    }

    /**
     * The Repeats options offered for a start date, value => label.
     *
     * Nth weekday is offered when n <= 4; last weekday when start + 7 days
     * falls in the next month.
     *
     * @return array<string, string>
     */
    public static function optionsFor(CarbonImmutable $startLocal): array
    {
        $dayName = $startLocal->englishDayOfWeek;
        $options = [
            RepeatPattern::None->value => 'Does not repeat',
            RepeatPattern::Weekly->value => "Weekly on {$dayName}",
        ];

        $nth = self::nth($startLocal);

        if ($nth <= 4) {
            $options[RepeatPattern::MonthlyNth->value] = 'Monthly on the '.self::ORDINALS[$nth]." {$dayName}";
        }

        if ($startLocal->addDays(7)->month !== $startLocal->month) {
            $options[RepeatPattern::MonthlyLast->value] = "Monthly on the last {$dayName}";
        }

        return $options;
    }

    public function isValidFor(CarbonImmutable $startLocal): bool
    {
        return array_key_exists($this->pattern->value, self::optionsFor($startLocal));
    }

    public function label(CarbonImmutable $startLocal): string
    {
        return self::optionsFor($startLocal)[$this->pattern->value]
            ?? throw new InvalidArgumentException("Pattern [{$this->pattern->value}] does not fit {$startLocal->toDateString()}.");
    }

    /**
     * @return array{repeats: string, repeat_ends: string, repeat_until: ?string, repeat_count: ?int}
     */
    public function toFormState(): array
    {
        return [
            'repeats' => $this->pattern->value,
            'repeat_ends' => $this->ends->value,
            'repeat_until' => $this->untilDate,
            'repeat_count' => $this->count,
        ];
    }

    /** Which occurrence of its weekday the date is within its month (1..5). */
    private static function nth(CarbonImmutable $date): int
    {
        return intdiv($date->day - 1, 7) + 1;
    }

    private function untilValue(bool $allDay): string
    {
        $until = self::localDate((string) $this->untilDate);

        if ($until === null) {
            throw new InvalidArgumentException("Invalid until date [{$this->untilDate}].");
        }

        return $allDay
            ? $until->format('Ymd')
            : $until->endOfDay()->startOfSecond()->utc()->format('Ymd\THis\Z');
    }

    /**
     * A stored UNTIL back to its London date. Both shapes are round-trip
     * checked, so an impossible value such as 20261345 is rejected instead of
     * overflowing (WR-03).
     */
    private static function untilToLocalDate(string $until): string
    {
        $format = strlen($until) === 8 ? '!Ymd' : 'Ymd\THis\Z';
        $value = self::parseExact($format, $until, strlen($until) === 8 ? self::TIMEZONE : 'UTC');

        if ($value === null) {
            throw new InvalidArgumentException("Invalid UNTIL [{$until}].");
        }

        return $value->setTimezone(self::TIMEZONE)->format('Y-m-d');
    }

    /**
     * A real Y-m-d calendar date in Europe/London, or null. Carbon overflows
     * impossible dates (2026-02-30 becomes 2 March), so the parsed value must
     * format back to exactly the input.
     */
    private static function localDate(string $value): ?CarbonImmutable
    {
        return self::parseExact('!Y-m-d', $value, self::TIMEZONE);
    }

    private static function parseExact(string $format, string $value, string $timezone): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat($format, $value, $timezone);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format(ltrim($format, '!')) !== $value) {
            return null;
        }

        return $parsed;
    }
}
