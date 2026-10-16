<?php

use App\Calendar\ManualRecurrence;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use Carbon\CarbonImmutable;

function london(string $date): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/London');
}

/*
 * Each rule string appears exactly once: the same dataset drives toRrule()
 * and the fromRrule() round trip.
 */
dataset('emitted rules', [
    'weekly, never ends' => [
        new ManualRecurrence(RepeatPattern::Weekly),
        '2026-10-23', false, 'FREQ=WEEKLY;BYDAY=FR',
    ],
    'second Tuesday, 6 times' => [
        new ManualRecurrence(RepeatPattern::MonthlyNth, RepeatEnds::AfterCount, count: 6),
        '2026-09-08', false, 'FREQ=MONTHLY;BYDAY=2TU;COUNT=6',
    ],
    'last Friday, timed, until 31 Mar 2027 (BST)' => [
        new ManualRecurrence(RepeatPattern::MonthlyLast, RepeatEnds::OnDate, untilDate: '2027-03-31'),
        '2026-10-30', false, 'FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z',
    ],
    'weekly all-day Sunday until 27 Dec 2026 (DATE form)' => [
        new ManualRecurrence(RepeatPattern::Weekly, RepeatEnds::OnDate, untilDate: '2026-12-27'),
        '2026-10-25', true, 'FREQ=WEEKLY;BYDAY=SU;UNTIL=20261227',
    ],
]);

it('builds the RRULE for each supported pattern and ending', function (ManualRecurrence $recurrence, string $start, bool $allDay, string $rule) {
    expect($recurrence->toRrule(london($start), $allDay))->toBe($rule);
})->with('emitted rules');

it('round-trips every emitted RRULE back to the same pattern, ending, until date and count', function (ManualRecurrence $recurrence, string $start, bool $allDay, string $rule) {
    $parsed = ManualRecurrence::fromRrule($rule, $allDay);

    expect($parsed->pattern)->toBe($recurrence->pattern)
        ->and($parsed->ends)->toBe($recurrence->ends)
        ->and($parsed->untilDate)->toBe($recurrence->untilDate)
        ->and($parsed->count)->toBe($recurrence->count);
})->with('emitted rules');

it('returns no rule for "Does not repeat"', function () {
    expect((new ManualRecurrence)->toRrule(london('2026-10-23'), false))->toBeNull();
});

it('rejects an RRULE shape it does not emit', function () {
    ManualRecurrence::fromRrule('FREQ=DAILY', false);
})->throws(InvalidArgumentException::class);

it('offers "second Tuesday" and no last option for Tue 8 Sep 2026', function () {
    $options = ManualRecurrence::optionsFor(london('2026-09-08'));

    expect($options)->toContain('Monthly on the second Tuesday')
        ->and($options)->not->toHaveKey(RepeatPattern::MonthlyLast->value);
});

it('offers "last Thursday" and no nth option for Thu 29 Oct 2026', function () {
    $options = ManualRecurrence::optionsFor(london('2026-10-29'));

    expect($options)->toContain('Monthly on the last Thursday')
        ->and($options)->not->toHaveKey(RepeatPattern::MonthlyNth->value);
});

it('offers "fourth Friday" and no last option for Fri 23 Oct 2026', function () {
    $options = ManualRecurrence::optionsFor(london('2026-10-23'));

    expect($options)->toContain('Monthly on the fourth Friday')
        ->and($options)->not->toHaveKey(RepeatPattern::MonthlyLast->value);
});

it('treats an nth-weekday pattern as invalid for a fifth-weekday start date', function () {
    expect((new ManualRecurrence(RepeatPattern::MonthlyNth))->isValidFor(london('2026-10-29')))->toBeFalse()
        ->and((new ManualRecurrence(RepeatPattern::MonthlyNth))->isValidFor(london('2026-10-22')))->toBeTrue();
});

it('treats a last-weekday pattern as invalid when start + 7 days is in the same month', function () {
    expect((new ManualRecurrence(RepeatPattern::MonthlyLast))->isValidFor(london('2026-10-23')))->toBeFalse()
        ->and((new ManualRecurrence(RepeatPattern::MonthlyLast))->isValidFor(london('2026-10-30')))->toBeTrue();
});

it('labels a pattern in plain British English', function () {
    expect((new ManualRecurrence(RepeatPattern::MonthlyLast))->label(london('2026-10-30')))->toBe('Monthly on the last Friday')
        ->and((new ManualRecurrence(RepeatPattern::Weekly))->label(london('2026-10-30')))->toBe('Weekly on Friday');
});
