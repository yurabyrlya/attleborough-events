<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function publishableData(array $overrides = []): PublishableEventData
{
    return new PublishableEventData(...array_merge([
        'title' => 'Harvest Supper',
        'description' => 'Two courses and a raffle.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-20',
        'startTime' => '19:30:00',
        'organiserContact' => 'supper@example.test',
    ], $overrides));
}

function utcColumn(int $occurrenceId, string $column): ?string
{
    $row = DB::selectOne("select {$column} at time zone 'UTC' as v from event_occurrences where id = ?", [$occurrenceId]);

    return $row->v === null ? null : substr((string) $row->v, 0, 19);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Europe/London'));
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
});

it('publishes with origin, approver and approval time recorded', function () {
    $event = app(PublishEvent::class)(publishableData(), $this->admin, EventOrigin::Manual);

    expect($event->status)->toBe(EventStatus::Published)
        ->and($event->origin)->toBe(EventOrigin::Manual)
        ->and($event->approved_by)->toBe($this->admin->id)
        ->and($event->approved_at)->not->toBeNull();
});

it('stores a 19:30 BST start on 20 Oct 2026 as 18:30 UTC', function () {
    $event = app(PublishEvent::class)(publishableData(), $this->admin, EventOrigin::Manual);

    $occurrence = EventOccurrence::where('event_id', $event->id)->sole();

    expect(utcColumn($occurrence->id, 'starts_at'))->toBe('2026-10-20 18:30:00');
});

it('stores ends_at NULL and expires_at = starts_at + 3h when there is no end time', function () {
    $event = app(PublishEvent::class)(publishableData(), $this->admin, EventOrigin::Manual);

    $occurrence = EventOccurrence::where('event_id', $event->id)->sole();

    expect(utcColumn($occurrence->id, 'ends_at'))->toBeNull()
        ->and(utcColumn($occurrence->id, 'expires_at'))->toBe('2026-10-20 21:30:00');
});

it('stores a Sat-Sun all-day event as exactly one occurrence ending Monday 00:00 London', function () {
    $event = app(PublishEvent::class)(publishableData([
        'startDate' => '2026-10-31',
        'endDate' => '2026-11-01',
        'startTime' => null,
        'allDay' => true,
    ]), $this->admin, EventOrigin::Manual);

    $occurrence = EventOccurrence::where('event_id', $event->id)->sole();

    expect(utcColumn($occurrence->id, 'starts_at'))->toBe('2026-10-31 00:00:00')
        ->and(utcColumn($occurrence->id, 'ends_at'))->toBe('2026-11-02 00:00:00')
        ->and(utcColumn($occurrence->id, 'expires_at'))->toBe('2026-11-02 00:00:00');
});

it('persists price, family-friendly, organiser and source note', function () {
    $event = app(PublishEvent::class)(publishableData([
        'familyFriendly' => true,
        'priceType' => PriceType::Paid,
        'priceText' => '£5, under-12s free',
        'organiserName' => 'St Mary\'s PTA',
        'sourceNote' => 'Poster in the library window',
    ]), $this->admin, EventOrigin::Manual);

    $fresh = $event->fresh();

    expect($fresh->family_friendly)->toBeTrue()
        ->and($fresh->price_type)->toBe(PriceType::Paid)
        ->and($fresh->price_text)->toBe('£5, under-12s free')
        ->and($fresh->organiser_name)->toBe('St Mary\'s PTA')
        ->and($fresh->source_note)->toBe('Poster in the library window');
});

it('persists nothing when occurrence generation throws', function () {
    // A timed event with no start time makes EventTiming throw inside the transaction.
    $data = publishableData(['startTime' => null, 'allDay' => false]);

    expect(fn () => app(PublishEvent::class)($data, $this->admin, EventOrigin::Manual))
        ->toThrow(InvalidArgumentException::class);

    expect(Event::count())->toBe(0)
        ->and(EventOccurrence::count())->toBe(0);
});

it('creates two separate events when invoked twice with identical data (no implicit dedupe)', function () {
    $data = publishableData();

    app(PublishEvent::class)($data, $this->admin, EventOrigin::Manual);
    app(PublishEvent::class)($data, $this->admin, EventOrigin::Manual);

    expect(Event::count())->toBe(2)
        ->and(EventOccurrence::count())->toBe(2);
});
