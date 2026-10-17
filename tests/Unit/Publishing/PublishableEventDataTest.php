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

it('accepts an end time equal to the start time when the event ends on a later day', function () {
    $data = validEventData(['endDate' => '2026-10-24', 'endTime' => '19:30']);

    expect($data->endTime)->toBe('19:30');
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
    'same-day end time equal to the start time' => [['startTime' => '19:30', 'endTime' => '19:30:00'], 'end time must be different'],
    'end time equal to the start time, end date on the start date' => [['startTime' => '19:30', 'endDate' => '2026-10-23', 'endTime' => '19:30'], 'end time must be different'],
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
    'no source URL, organiser contact or website' => [
        ['sourceUrl' => null, 'organiserContact' => null, 'organiserWebsite' => null],
        'Add a source URL, an organiser contact or a website.',
    ],
    'whitespace-only source, contact and website' => [
        ['sourceUrl' => '  ', 'organiserContact' => ' ', 'organiserWebsite' => '   '],
        'Add a source URL, an organiser contact or a website.',
    ],
]);

it('accepts an organiser website as the only way to check the event', function () {
    $data = validEventData([
        'organiserContact' => null,
        'sourceUrl' => null,
        'organiserWebsite' => 'https://club.example/events',
    ]);

    expect($data->organiserWebsite)->toBe('https://club.example/events');
});

it('writes contact_public and organiser_website to the event attributes', function () {
    $attributes = validEventData([
        'organiserWebsite' => 'https://club.example/events',
        'contactPublic' => false,
    ])->toAttributes();

    expect($attributes['contact_public'])->toBeFalse()
        ->and($attributes['organiser_website'])->toBe('https://club.example/events');
});

it('defaults to a public contact and no website', function () {
    $attributes = validEventData()->toAttributes();

    expect($attributes['contact_public'])->toBeTrue()
        ->and($attributes['organiser_website'])->toBeNull();
});

it('maps the website and contact choice from the form state', function () {
    $data = PublishableEventData::fromForm([
        'title' => 'Harvest Supper',
        'description' => 'Two courses and a raffle.',
        'category_id' => 1,
        'venue_id' => 1,
        'start_date' => '2026-10-23',
        'start_time' => '19:30',
        'organiser_website' => ' https://club.example/events ',
        'contact_public' => false,
    ]);

    expect($data->organiserWebsite)->toBe('https://club.example/events')
        ->and($data->contactPublic)->toBeFalse();
});
