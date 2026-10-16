<?php

use App\Calendar\EventTiming;
use App\Models\Event;

/**
 * DST fixtures: BST ends Sun 25 Oct 2026 (25h day); BST starts Sun 28 Mar 2027 (23h day).
 */
function timingEvent(array $attributes): Event
{
    return (new Event)->forceFill(array_merge([
        'timezone' => 'Europe/London',
        'all_day' => false,
        'start_time' => null,
        'end_date' => null,
        'end_time' => null,
    ], $attributes));
}

function iso(?\Carbon\CarbonImmutable $value): ?string
{
    return $value?->utc()->format('Y-m-d\TH:i:s\Z');
}

it('maps a timed event with an end time to UTC instants (BST)', function () {
    $event = timingEvent(['start_date' => '2026-10-24', 'start_time' => '19:30:00', 'end_time' => '22:00:00']);

    $t = (new EventTiming(3))->forDate($event, '2026-10-24');

    expect($t->key)->toBe('2026-10-24')
        ->and(iso($t->startsAt))->toBe('2026-10-24T18:30:00Z')
        ->and(iso($t->endsAt))->toBe('2026-10-24T21:00:00Z')
        ->and(iso($t->expiresAt))->toBe('2026-10-24T21:00:00Z');
});

it('expires a timed event with no end time 3 hours after its start', function () {
    $event = timingEvent(['start_date' => '2026-10-24', 'start_time' => '19:30:00']);

    $t = (new EventTiming(3))->forDate($event, '2026-10-24');

    expect($t->endsAt)->toBeNull()
        ->and(iso($t->startsAt))->toBe('2026-10-24T18:30:00Z')
        ->and(iso($t->expiresAt))->toBe('2026-10-24T21:30:00Z');
});

it('rolls an end time earlier than the start over to the next day across the 2026-10-25 DST change', function () {
    $event = timingEvent(['start_date' => '2026-10-24', 'start_time' => '23:30:00', 'end_time' => '02:30:00']);

    $t = (new EventTiming(3))->forDate($event, '2026-10-24');

    // 3h wall-clock, 4h absolute: 22:30Z (BST) -> 02:30Z (GMT)
    expect(iso($t->startsAt))->toBe('2026-10-24T22:30:00Z')
        ->and(iso($t->endsAt))->toBe('2026-10-25T02:30:00Z')
        ->and(iso($t->expiresAt))->toBe('2026-10-25T02:30:00Z')
        ->and($t->startsAt->diffInHours($t->endsAt))->toEqual(4);
});

it('makes an all-day event on Sun 2026-10-25 span the 25-hour local day', function () {
    $event = timingEvent(['start_date' => '2026-10-25', 'all_day' => true]);

    $t = (new EventTiming(3))->forDate($event, '2026-10-25');

    expect($t->key)->toBe('2026-10-25')
        ->and(iso($t->startsAt))->toBe('2026-10-24T23:00:00Z')
        ->and(iso($t->endsAt))->toBe('2026-10-26T00:00:00Z')
        ->and(iso($t->expiresAt))->toBe('2026-10-26T00:00:00Z')
        ->and($t->startsAt->diffInHours($t->endsAt))->toEqual(25);
});

it('makes an all-day event on Sun 2027-03-28 span the 23-hour local day', function () {
    $event = timingEvent(['start_date' => '2027-03-28', 'all_day' => true]);

    $t = (new EventTiming(3))->forDate($event, '2027-03-28');

    expect(iso($t->startsAt))->toBe('2027-03-28T00:00:00Z')
        ->and(iso($t->endsAt))->toBe('2027-03-28T23:00:00Z')
        ->and($t->startsAt->diffInHours($t->endsAt))->toEqual(23);
});

it('makes a Sat-Sun all-day event one timing ending Monday 00:00 London (exclusive)', function () {
    $event = timingEvent(['start_date' => '2026-10-31', 'end_date' => '2026-11-01', 'all_day' => true]);

    $t = (new EventTiming(3))->forDate($event, '2026-10-31');

    expect($t->key)->toBe('2026-10-31')
        ->and(iso($t->startsAt))->toBe('2026-10-31T00:00:00Z')
        ->and(iso($t->endsAt))->toBe('2026-11-02T00:00:00Z')
        ->and(iso($t->expiresAt))->toBe('2026-11-02T00:00:00Z');
});
