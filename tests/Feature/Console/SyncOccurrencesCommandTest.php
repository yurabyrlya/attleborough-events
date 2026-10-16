<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
});

function publishWeeklyFriday(User $admin, string $title = 'Repair Café'): Event
{
    return app(PublishEvent::class)(new PublishableEventData(
        title: $title,
        description: 'Bring broken things.',
        categoryId: Category::factory()->create()->id,
        venueId: Venue::factory()->create()->id,
        startDate: '2026-10-23',
        startTime: '10:00:00',
        organiserContact: 'repair@example.test',
        repeats: RepeatPattern::Weekly,
    ), $admin, EventOrigin::Manual);
}

/**
 * @return array<string, array{id: int, status: string, starts: string, ends: ?string, expires: string}>
 */
function syncSnapshot(Event $event): array
{
    $rows = DB::select(
        "select id, recurrence_key, status, starts_at at time zone 'UTC' as s, ends_at at time zone 'UTC' as e, expires_at at time zone 'UTC' as x
           from event_occurrences where event_id = ? order by starts_at",
        [$event->id],
    );

    return collect($rows)->mapWithKeys(fn ($r) => [$r->recurrence_key => [
        'id' => (int) $r->id,
        'status' => $r->status,
        'starts' => (string) $r->s,
        'ends' => $r->e === null ? null : (string) $r->e,
        'expires' => (string) $r->x,
    ]])->all();
}

it('rolls the horizon forward by exactly one new weekly row and keeps existing ids', function () {
    $event = publishWeeklyFriday($this->admin);
    $before = syncSnapshot($event);
    expect($before)->toHaveCount(13);

    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));

    $this->artisan('calendar:sync-occurrences')
        ->expectsOutputToContain('Synced 1 series, 0 failed')
        ->assertExitCode(0);

    $after = syncSnapshot($event);
    $new = array_diff_key($after, $before);

    expect(array_keys($new))->toBe(['2027-01-22'])
        ->and(array_column(array_intersect_key($after, $before), 'id'))->toBe(array_column($before, 'id'));
});

it('keeps an admin-cancelled occurrence cancelled and also extends a hidden event', function () {
    $event = publishWeeklyFriday($this->admin);
    DB::table('event_occurrences')->where('event_id', $event->id)->where('recurrence_key', '2026-11-06')->update(['status' => 'cancelled']);
    DB::table('events')->where('id', $event->id)->update(['status' => EventStatus::Hidden->value]);

    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));
    $this->artisan('calendar:sync-occurrences')->assertExitCode(0);

    $after = syncSnapshot($event);

    expect($after['2026-11-06']['status'])->toBe('cancelled')
        ->and($after)->toHaveKey('2027-01-22');
});

it('leaves zero upcoming rows for a series whose UNTIL has passed, keeping rows that already started', function () {
    $event = publishWeeklyFriday($this->admin);
    DB::table('events')->where('id', $event->id)->update(['recurrence_rule' => 'FREQ=WEEKLY;BYDAY=FR;UNTIL=20261030T235959Z']);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 03:10', 'Europe/London'));
    $this->artisan('calendar:sync-occurrences')->assertExitCode(0);

    expect(array_keys(syncSnapshot($event)))->toBe(['2026-10-23', '2026-10-30']);
});

it('changes nothing when run twice in a row', function () {
    $event = publishWeeklyFriday($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));

    $this->artisan('calendar:sync-occurrences')->assertExitCode(0);
    $once = syncSnapshot($event);
    $this->artisan('calendar:sync-occurrences')->assertExitCode(0);

    expect(syncSnapshot($event))->toBe($once)->and($once)->toHaveKey('2027-01-22');
});

it('isolates a failing event: the others still sync, the exit code is 1 and the failing id is named', function () {
    $broken = publishWeeklyFriday($this->admin, 'Broken');
    $healthy = publishWeeklyFriday($this->admin, 'Healthy');
    DB::table('events')->where('id', $broken->id)->update(['recurrence_rule' => 'FREQ=BOGUS']);

    $this->travelTo(CarbonImmutable::parse('2026-10-27 03:10', 'Europe/London'));

    $this->artisan('calendar:sync-occurrences')
        ->expectsOutputToContain('Synced 1 series, 1 failed')
        ->expectsOutputToContain("Failed event ids: {$broken->id}")
        ->assertExitCode(1);

    expect(syncSnapshot($healthy))->toHaveKey('2027-01-22');
});

it('schedules calendar:sync-occurrences daily at 03:10 Europe/London without overlapping', function () {
    app(Kernel::class)->bootstrap();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'calendar:sync-occurrences'));

    expect($events)->toHaveCount(1);
    $entry = $events->first();

    expect($entry->expression)->toBe('10 3 * * *')
        ->and((string) $entry->timezone)->toBe('Europe/London')
        ->and($entry->withoutOverlapping)->toBeTrue();
});
