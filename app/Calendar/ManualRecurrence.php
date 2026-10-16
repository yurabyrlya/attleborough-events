<?php

namespace App\Calendar;

use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

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

        if ($ends === RepeatEnds::OnDate && ($untilDate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilDate) !== 1)) {
            throw new InvalidArgumentException('A series that ends on a date needs that date (Y-m-d).');
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
        $until = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->untilDate, self::TIMEZONE);

        if (! $until instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Invalid until date [{$this->untilDate}].");
        }

        return $allDay
            ? $until->format('Ymd')
            : $until->endOfDay()->startOfSecond()->utc()->format('Ymd\THis\Z');
    }

    private static function untilToLocalDate(string $until): string
    {
        $value = strlen($until) === 8
            ? CarbonImmutable::createFromFormat('!Ymd', $until, self::TIMEZONE)
            : CarbonImmutable::createFromFormat('Ymd\THis\Z', $until, 'UTC')?->setTimezone(self::TIMEZONE);

        if (! $value instanceof CarbonImmutable) {
            throw new InvalidArgumentException("Invalid UNTIL [{$until}].");
        }

        return $value->format('Y-m-d');
    }
}
