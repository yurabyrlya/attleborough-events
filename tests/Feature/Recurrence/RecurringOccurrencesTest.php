<?php

use App\Enums\EventOrigin;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function seriesData(array $overrides = []): PublishableEventData
{
    return new PublishableEventData(...array_merge([
        'title' => 'Repair Café',
        'description' => 'Bring broken things.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '10:00:00',
        'organiserContact' => 'repair@example.test',
        'repeats' => RepeatPattern::Weekly,
    ], $overrides));
}

function publishSeries(array $overrides = []): Event
{
    $admin = User::query()->firstOrCreate(
        ['email' => 'admin@example.test'],
        ['name' => 'Admin', 'password' => 'secret-password'],
    );

    return app(PublishEvent::class)(seriesData($overrides), $admin, EventOrigin::Manual);
}

/**
 * Raw UTC instants per recurrence key, read with AT TIME ZONE 'UTC'.
 *
 * @return array<string, array{id: int, starts: string, ends: ?string, expires: string}>
 */
function rawSeriesRows(Event $event): array
{
    $rows = DB::select(
        "select id, recurrence_key,
                starts_at at time zone 'UTC' as s,
                ends_at at time zone 'UTC' as e,
                expires_at at time zone 'UTC' as x
           from event_occurrences where event_id = ? order by starts_at",
        [$event->id],
    );

    $trim = fn ($v): ?string => $v === null ? null : substr((string) $v, 0, 19);

    return collect($rows)->mapWithKeys(fn ($r) => [$r->recurrence_key => [
        'id' => (int) $r->id,
        'starts' => $trim($r->s),
        'ends' => $trim($r->e),
        'expires' => $trim($r->x),
    ]])->all();
}

function travelToLondon(string $when): void
{
    test()->travelTo(CarbonImmutable::parse($when, 'Europe/London'));
}

it('keeps a weekly Friday 10:00 series at 10:00 local across the 28 Mar 2027 DST change', function () {
    travelToLondon('2027-03-21 09:00');

    $rows = rawSeriesRows(publishSeries(['startDate' => '2027-03-26']));

    expect($rows['2027-03-26']['starts'])->toBe('2027-03-26 10:00:00')
        ->and($rows['2027-04-02']['starts'])->toBe('2027-04-02 09:00:00');
});

it('shifts a nonexistent 01:30 on Sun 28 Mar 2027 forward to 02:30 BST (01:30Z)', function () {
    travelToLondon('2027-03-21 00:10');

    $rows = rawSeriesRows(publishSeries(['startDate' => '2027-03-21', 'startTime' => '01:30:00']));

    expect($rows['2027-03-28']['starts'])->toBe('2027-03-28 01:30:00')
        ->and($rows['2027-04-04']['starts'])->toBe('2027-04-04 00:30:00');
});

it('handles a weekly Saturday 23:30-02:30 series over the 25 Oct 2026 DST change', function () {
    travelToLondon('2026-10-20 10:00');

    $rows = rawSeriesRows(publishSeries(['startDate' => '2026-10-24', 'startTime' => '23:30:00', 'endTime' => '02:30:00']));

    expect($rows['2026-10-24']['starts'])->toBe('2026-10-24 22:30:00')
        ->and($rows['2026-10-24']['ends'])->toBe('2026-10-25 02:30:00')
        ->and($rows['2026-10-31']['starts'])->toBe('2026-10-31 23:30:00')
        ->and($rows['2026-10-31']['ends'])->toBe('2026-11-01 02:30:00');
});

it('gives an all-day Sunday series one row per Sunday, 25 hours long on 25 Oct 2026', function () {
    travelToLondon('2026-10-20 10:00');

    $rows = rawSeriesRows(publishSeries(['startDate' => '2026-10-25', 'startTime' => null, 'allDay' => true]));

    expect($rows['2026-10-25'])->toMatchArray([
        'starts' => '2026-10-24 23:00:00',
        'ends' => '2026-10-26 00:00:00',
        'expires' => '2026-10-26 00:00:00',
    ])->and($rows['2026-11-01'])->toMatchArray([
        'starts' => '2026-11-01 00:00:00',
        'ends' => '2026-11-02 00:00:00',
    ]);
});

it('gives the all-day Sunday 28 Mar 2027 instance a 23-hour span', function () {
    travelToLondon('2027-03-21 09:00');

    $rows = rawSeriesRows(publishSeries(['startDate' => '2027-03-21', 'startTime' => null, 'allDay' => true]));

    expect($rows['2027-03-28'])->toMatchArray([
        'starts' => '2027-03-28 00:00:00',
        'ends' => '2027-03-28 23:00:00',
    ]);
});

it('stores ends_at NULL and expires_at = starts_at + 3h for a recurring instance with no end, both sides of 25 Oct 2026', function () {
    travelToLondon('2026-10-20 10:00');

    $rows = rawSeriesRows(publishSeries(['startTime' => '19:30:00']));

    expect($rows['2026-10-23'])->toMatchArray(['starts' => '2026-10-23 18:30:00', 'ends' => null, 'expires' => '2026-10-23 21:30:00'])
        ->and($rows['2026-10-30'])->toMatchArray(['starts' => '2026-10-30 19:30:00', 'ends' => null, 'expires' => '2026-10-30 22:30:00']);
});

it('yields exactly one occurrence for COUNT=1', function () {
    travelToLondon('2026-10-20 10:00');

    $event = publishSeries(['repeatEnds' => RepeatEnds::AfterCount, 'repeatCount' => 1]);

    expect($event->recurrence_rule)->toBe('FREQ=WEEKLY;BYDAY=FR;COUNT=1')
        ->and(rawSeriesRows($event))->toHaveCount(1);
});

it('stores a monthly last-Friday series that ends on a date and stops after it', function () {
    travelToLondon('2026-10-20 10:00');

    $event = publishSeries([
        'startDate' => '2026-10-30',
        'repeats' => RepeatPattern::MonthlyLast,
        'repeatEnds' => RepeatEnds::OnDate,
        'repeatUntil' => '2026-12-25',
    ]);

    expect(array_keys(rawSeriesRows($event)))->toBe(['2026-10-30', '2026-11-27', '2026-12-25']);
});

it('inserts a series in chronological order so ids ascend with starts_at', function () {
    travelToLondon('2026-10-20 10:00');

    $ids = array_column(rawSeriesRows(publishSeries()), 'id');
    $sorted = $ids;
    sort($sorted);

    expect($ids)->toBe($sorted)->and($ids)->toHaveCount(13);
});

it('refuses a repeating event that spans several days', function () {
    seriesData(['endDate' => '2026-10-24']);
})->throws(InvalidArgumentException::class, "Repeating events can't span several days");

it('normalises an end date equal to the start date to null for a repeating event', function () {
    $attributes = seriesData(['endDate' => '2026-10-23'])->toAttributes();

    expect($attributes['end_date'])->toBeNull();
});

it('refuses a monthly nth-weekday pattern that does not fit the start date', function () {
    seriesData(['startDate' => '2026-10-29', 'repeats' => RepeatPattern::MonthlyNth]);
})->throws(InvalidArgumentException::class);
