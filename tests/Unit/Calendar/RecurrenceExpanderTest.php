<?php

use App\Calendar\ManualRecurrence;
use App\Calendar\RecurrenceExpander;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * An unsaved series: the expander only reads timezone, all_day, start_date,
 * start_time and recurrence_rule, so no database is needed.
 */
function unsavedSeries(string $startDate, string $rule, ?string $startTime = '19:30:00', bool $allDay = false): Event
{
    return new Event([
        'timezone' => 'Europe/London',
        'all_day' => $allDay,
        'start_date' => $startDate,
        'start_time' => $allDay ? null : $startTime,
        'recurrence_rule' => $rule,
    ]);
}

function londonMidnight(string $date): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/London');
}

/**
 * @return list<string>
 */
function expand(Event $event, string $windowStart, ?string $windowEnd = null): array
{
    $start = londonMidnight($windowStart);
    $end = $windowEnd === null ? $start->addDays(90) : londonMidnight($windowEnd);

    return (new RecurrenceExpander)->instanceDates($event, $start, $end);
}

function ruleFor(ManualRecurrence $recurrence, string $startDate, bool $allDay = false): string
{
    return (string) $recurrence->toRrule(londonMidnight($startDate), $allDay);
}

it('expands the second Tuesday COUNT=6 series inside a window with an exclusive end', function () {
    $series = unsavedSeries('2026-09-08', ruleFor(new ManualRecurrence(RepeatPattern::MonthlyNth, RepeatEnds::AfterCount, count: 6), '2026-09-08'));

    expect(expand($series, '2026-10-16', '2027-01-14'))->toBe(['2026-11-10', '2026-12-08', '2027-01-12']);
});

it('counts the first date in COUNT, so the sixth and last instance is 9 Feb 2027', function () {
    $series = unsavedSeries('2026-09-08', ruleFor(new ManualRecurrence(RepeatPattern::MonthlyNth, RepeatEnds::AfterCount, count: 6), '2026-09-08'));

    expect(expand($series, '2027-01-20'))->toBe(['2027-02-09']);
});

it('includes the UNTIL date itself and nothing after it', function () {
    $series = unsavedSeries('2026-10-30', ruleFor(new ManualRecurrence(RepeatPattern::MonthlyLast, RepeatEnds::OnDate, untilDate: '2027-03-26'), '2026-10-30'));

    expect(expand($series, '2027-03-01'))->toBe(['2027-03-26']);
});

it('materialises an instance 89 days after today', function () {
    $today = londonMidnight('2026-10-20');
    $first = $today->addDays(89);
    $series = unsavedSeries($first->format('Y-m-d'), ruleFor(new ManualRecurrence(RepeatPattern::Weekly), $first->format('Y-m-d')));

    expect(expand($series, '2026-10-20'))->toBe([$first->format('Y-m-d')]);
});

it('does not materialise an instance exactly 90 days after today (exclusive horizon end)', function () {
    $first = londonMidnight('2026-10-20')->addDays(90);
    $series = unsavedSeries($first->format('Y-m-d'), ruleFor(new ManualRecurrence(RepeatPattern::Weekly), $first->format('Y-m-d')));

    expect(expand($series, '2026-10-20'))->toBe([]);
});

it('returns 13 dates (today .. today + 84) for a weekly series starting today', function () {
    $series = unsavedSeries('2026-10-20', ruleFor(new ManualRecurrence(RepeatPattern::Weekly), '2026-10-20'));

    $dates = expand($series, '2026-10-20');

    expect($dates)->toHaveCount(13)
        ->and($dates[0])->toBe('2026-10-20')
        ->and($dates[12])->toBe(londonMidnight('2026-10-20')->addDays(84)->format('Y-m-d'));
});

it('returns no dates instead of throwing when UNTIL is before the first date', function () {
    $series = unsavedSeries('2026-10-30', ruleFor(new ManualRecurrence(RepeatPattern::Weekly, RepeatEnds::OnDate, untilDate: '2026-10-01'), '2026-10-30'));

    expect(expand($series, '2026-10-20'))->toBe([]);
});

it('keys all-day instances by their London date', function () {
    $series = unsavedSeries('2026-10-25', ruleFor(new ManualRecurrence(RepeatPattern::Weekly), '2026-10-25', true), allDay: true);

    expect(array_slice(expand($series, '2026-10-20'), 0, 2))->toBe(['2026-10-25', '2026-11-01']);
});

it('refuses to expand more than MAX_INSTANCES instances', function () {
    $series = unsavedSeries('2026-10-20', ruleFor(new ManualRecurrence(RepeatPattern::Weekly), '2026-10-20'));

    expand($series, '2026-10-20', '2040-01-01');
})->throws(RuntimeException::class);
