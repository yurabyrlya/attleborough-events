<?php

namespace App\Calendar;

use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Form state <-> RRULE for the admin's simple recurrence choices.
 *
 * The admin never types an RRULE: it is built only here, from the enum and a
 * validated date/count, and fromRrule() rejects any shape this class does not
 * emit (T-01-17).
 *
 * Stored format: a bare RFC 5545 RRULE value without the "RRULE:" prefix.
 */
final readonly class ManualRecurrence
{
    private const DAY_CODES = [
        'Monday' => 'MO',
        'Tuesday' => 'TU',
        'Wednesday' => 'WE',
        'Thursday' => 'TH',
        'Friday' => 'FR',
        'Saturday' => 'SA',
        'Sunday' => 'SU',
    ];

    public function __construct(
        public RepeatPattern $pattern = RepeatPattern::None,
        public RepeatEnds $ends = RepeatEnds::Never,
        public ?string $untilDate = null,
        public ?int $count = null,
    ) {}

    /**
     * @param  CarbonImmutable  $startLocal  The series' first date, in Europe/London
     */
    public function toRrule(CarbonImmutable $startLocal, bool $allDay): ?string
    {
        $day = self::DAY_CODES[$startLocal->englishDayOfWeek];

        return match ($this->pattern) {
            RepeatPattern::None => null,
            RepeatPattern::Weekly => "FREQ=WEEKLY;BYDAY={$day}",
            default => throw new InvalidArgumentException("Unsupported repeat pattern [{$this->pattern->value}]."),
        };
    }

    public static function fromRrule(?string $rrule, bool $allDay): self
    {
        if ($rrule === null || trim($rrule) === '') {
            return new self;
        }

        if (preg_match('/^FREQ=WEEKLY;BYDAY=(MO|TU|WE|TH|FR|SA|SU)$/', $rrule) === 1) {
            return new self(RepeatPattern::Weekly);
        }

        throw new InvalidArgumentException("Unsupported recurrence rule [{$rrule}].");
    }

    /**
     * The Repeats options offered for a start date, value => label.
     *
     * @return array<string, string>
     */
    public static function optionsFor(CarbonImmutable $startLocal): array
    {
        return [
            RepeatPattern::None->value => 'Does not repeat',
            RepeatPattern::Weekly->value => "Weekly on {$startLocal->englishDayOfWeek}",
        ];
    }

    public function isValidFor(CarbonImmutable $startLocal): bool
    {
        return true; // TODO(01-04 Task 2): implemented in GREEN
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
}
