<?php

use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Publishing\Data\PublishableEventData;

/*
 * The DTO's own invariants (WR-02): the non-Filament publish path (Phase 2
 * approvals) must refuse what the form refuses, with a message naming the
 * problem. Category and venue ids are plain ints: no database is needed.
 */
function validEventData(array $overrides = []): PublishableEventData
{
    return new PublishableEventData(...array_merge([
        'title' => 'Harvest Supper',
        'description' => 'Two courses and a raffle.',
        'categoryId' => 1,
        'venueId' => 1,
        'startDate' => '2026-10-23',
        'startTime' => '19:30',
        'organiserContact' => 'supper@example.test',
    ], $overrides));
}

it('builds from valid input', function () {
    $data = validEventData();

    expect($data->title)->toBe('Harvest Supper')
        ->and($data->startDate)->toBe('2026-10-23');
});

it('accepts an all-day event with no start time and no end', function () {
    $data = validEventData(['allDay' => true, 'startTime' => null]);

    expect($data->allDay)->toBeTrue()
        ->and($data->startTime)->toBeNull()
        ->and($data->endDate)->toBeNull();
});

it('accepts start times as HH:MM and HH:MM:SS', function (string $time) {
    expect(validEventData(['startTime' => $time])->startTime)->toBe($time);
})->with(['19:30', '19:30:00']);

it('accepts a source URL without an organiser contact', function () {
    $data = validEventData(['organiserContact' => null, 'sourceUrl' => 'https://example.test/poster']);

    expect($data->sourceUrl)->toBe('https://example.test/poster');
});

it('accepts an ends-on-a-date series whose last date is after the start date', function () {
    $data = validEventData([
        'repeats' => RepeatPattern::Weekly,
        'repeatEnds' => RepeatEnds::OnDate,
        'repeatUntil' => '2026-12-18',
    ]);

    expect($data->isRecurring())->toBeTrue();
});

it('rejects input the publish form would refuse, naming the problem', function (array $overrides, string $fragment) {
    expect(fn () => validEventData($overrides))
        ->toThrow(InvalidArgumentException::class, $fragment);
})->with([
    'blank title' => [['title' => '   '], 'title'],
    'empty description' => [['description' => ''], 'description'],
    'impossible start date (30 Feb)' => [['startDate' => '2026-02-30'], 'start date'],
    'impossible start date (month 13)' => [['startDate' => '2026-13-45'], 'start date'],
    'unparseable start date' => [['startDate' => 'tomorrow!!'], 'start date'],
    'impossible end date' => [['endDate' => '2026-11-31'], 'end date'],
    'end date before start date' => [['startDate' => '2026-11-05', 'endDate' => '2026-11-01'], 'end date'],
    'start time out of range' => [['startTime' => '25:00'], 'start time'],
    'malformed end time' => [['endTime' => '7pm'], 'end time'],
    'timed event without a start time' => [['allDay' => false, 'startTime' => null], 'start time'],
    'impossible last date' => [[
        'repeats' => RepeatPattern::Weekly,
        'repeatEnds' => RepeatEnds::OnDate,
        'repeatUntil' => '2026-02-30',
    ], 'last date'],
    'last date before start date' => [[
        'repeats' => RepeatPattern::Weekly,
        'repeatEnds' => RepeatEnds::OnDate,
        'repeatUntil' => '2026-10-01',
    ], 'last date'],
    'neither source URL nor organiser contact' => [
        ['sourceUrl' => null, 'organiserContact' => null],
        'source URL or an organiser contact',
    ],
    'whitespace-only source and contact' => [
        ['sourceUrl' => '  ', 'organiserContact' => ' '],
        'source URL or an organiser contact',
    ],
]);
