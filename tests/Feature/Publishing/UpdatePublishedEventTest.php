<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    $this->baseData = [
        'title' => 'Repair Café',
        'description' => 'Bring broken things.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '10:00:00',
        'organiserContact' => 'repair@example.test',
        'repeats' => RepeatPattern::Weekly,
    ];
});

function editData(array $base, array $overrides = []): PublishableEventData
{
    return new PublishableEventData(...array_merge($base, $overrides));
}

/**
 * @return array<string, array{id: int, starts: string, ends: ?string, expires: string, status: string}>
 */
function occurrenceSnapshot(Event $event): array
{
    $rows = DB::select(
        "select id, recurrence_key, status,
                starts_at at time zone 'UTC' as s, ends_at at time zone 'UTC' as e, expires_at at time zone 'UTC' as x
           from event_occurrences where event_id = ? order by starts_at",
        [$event->id],
    );
    $trim = fn ($v): ?string => $v === null ? null : substr((string) $v, 0, 19);

    return collect($rows)->mapWithKeys(fn ($r) => [$r->recurrence_key => [
        'id' => (int) $r->id,
        'starts' => $trim($r->s),
        'ends' => $trim($r->e),
        'expires' => $trim($r->x),
        'status' => $r->status,
    ]])->all();
}

function setOccurrenceStatus(Event $event, string $key, OccurrenceStatus $status): void
{
    // Simulates the admin action that plan 01-05 supplies.
    DB::table('event_occurrences')
        ->where('event_id', $event->id)
        ->where('recurrence_key', $key)
        ->update(['status' => $status->value]);
}

it('keeps every recurrence key, id and admin status when only the start time changes', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);
    setOccurrenceStatus($event, '2026-11-06', OccurrenceStatus::Cancelled);
    $before = occurrenceSnapshot($event);

    app(UpdatePublishedEvent::class)($event, editData($this->baseData, ['startTime' => '11:00:00']), $this->admin);

    $after = occurrenceSnapshot($event);

    expect(array_keys($after))->toBe(array_keys($before))
        ->and(array_column($after, 'id'))->toBe(array_column($before, 'id'))
        ->and($after['2026-11-06']['status'])->toBe('cancelled')
        ->and($after['2026-11-06']['starts'])->toBe('2026-11-06 11:00:00')
        ->and($after['2026-10-23']['starts'])->toBe('2026-10-23 10:00:00');
});

it('prunes future rows of the old weekday (even cancelled ones) and keeps past rows when the weekday changes', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'Europe/London'));
    setOccurrenceStatus($event, '2026-11-13', OccurrenceStatus::Cancelled);
    $past = array_intersect_key(occurrenceSnapshot($event), array_flip(['2026-10-23', '2026-10-30']));

    app(UpdatePublishedEvent::class)($event, editData($this->baseData, ['startDate' => '2026-10-24']), $this->admin);

    $after = occurrenceSnapshot($event);
    $futureKeys = array_keys(array_diff_key($after, $past));

    expect(array_intersect_key($after, $past))->toBe($past)
        ->and($after)->not->toHaveKey('2026-11-13')
        ->and($futureKeys[0])->toBe('2026-11-07')
        ->and(collect($futureKeys)->every(fn ($k) => CarbonImmutable::parse($k)->isSaturday()))->toBeTrue()
        ->and(EventOccurrence::where('event_id', $event->id)->where('status', 'cancelled')->count())->toBe(0);
});

it('changes nothing when the same data is saved again', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);
    setOccurrenceStatus($event, '2026-10-30', OccurrenceStatus::Hidden);
    $before = occurrenceSnapshot($event);

    app(UpdatePublishedEvent::class)($event, editData($this->baseData), $this->admin);

    expect(occurrenceSnapshot($event))->toBe($before);
});

it('leaves exactly one future occurrence keyed by start_date when a series becomes "Does not repeat"', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);

    $updated = app(UpdatePublishedEvent::class)($event, editData($this->baseData, ['repeats' => RepeatPattern::None]), $this->admin);

    expect($updated->recurrence_rule)->toBeNull()
        ->and(array_keys(occurrenceSnapshot($event)))->toBe(['2026-10-23']);
});

it('sets ends_at NULL and expires_at = starts_at + 3h on every future occurrence when the end time is cleared', function () {
    $withEnd = [...$this->baseData, 'endTime' => '12:00:00'];
    $event = app(PublishEvent::class)(editData($withEnd), $this->admin, EventOrigin::Manual);

    app(UpdatePublishedEvent::class)($event, editData($withEnd, ['endTime' => null]), $this->admin);

    $rows = occurrenceSnapshot($event);

    expect($rows)->toHaveCount(13)
        ->and(collect($rows)->every(fn ($r) => $r['ends'] === null
            && CarbonImmutable::parse($r['expires'], 'UTC')->equalTo(CarbonImmutable::parse($r['starts'], 'UTC')->addHours(3))))->toBeTrue();
});

it('never changes status, origin, approver or approval time', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);
    DB::table('events')->where('id', $event->id)->update(['status' => EventStatus::Hidden->value]);
    $before = DB::table('events')->where('id', $event->id)->first(['status', 'origin', 'approved_by', 'approved_at']);

    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Europe/London'));
    app(UpdatePublishedEvent::class)($event->fresh(), editData($this->baseData, ['title' => 'Repair Café (new venue)']), $this->admin);

    $after = DB::table('events')->where('id', $event->id)->first(['title', 'status', 'origin', 'approved_by', 'approved_at']);

    expect($after->title)->toBe('Repair Café (new venue)')
        ->and($after->status)->toBe($before->status)
        ->and($after->origin)->toBe($before->origin)
        ->and($after->approved_by)->toBe($before->approved_by)
        ->and($after->approved_at)->toBe($before->approved_at);
});

it('refuses an editor who is not the admin and changes nothing', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => app(UpdatePublishedEvent::class)($event, editData($this->baseData, ['title' => 'Hacked']), $intruder))
        ->toThrow(AuthorizationException::class);

    expect($event->fresh()->title)->toBe('Repair Café');
});

it('rolls the event back when the occurrence sync throws', function () {
    $event = app(PublishEvent::class)(editData($this->baseData), $this->admin, EventOrigin::Manual);

    // A horizon long enough to exceed RecurrenceExpander::MAX_INSTANCES makes
    // the real sync throw inside the transaction (the generator is final).
    config(['calendar.horizon_days' => 5000]);

    expect(fn () => app(UpdatePublishedEvent::class)($event, editData($this->baseData, ['title' => 'Should not stick', 'startTime' => '11:00:00']), $this->admin))
        ->toThrow(RuntimeException::class);

    $fresh = $event->fresh();

    expect($fresh->title)->toBe('Repair Café')
        ->and(substr((string) $fresh->start_time, 0, 5))->toBe('10:00');
});
