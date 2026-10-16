<?php

use App\Enums\EventOrigin;
use App\Enums\HealthState;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\Heartbeat;
use App\Models\User;
use App\Models\Venue;
use App\Ops\HealthReport;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));
    $this->admin = OpsFixtures::admin();
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 10);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 10, ['latency_seconds' => 1]);
});

function publishOpsSeries(User $admin, string $title): Event
{
    return app(PublishEvent::class)(new PublishableEventData(
        title: $title,
        description: 'Weekly.',
        categoryId: Category::factory()->create()->id,
        venueId: Venue::factory()->create()->id,
        startDate: '2026-10-23',
        startTime: '10:00:00',
        organiserContact: 'ops@example.test',
        repeats: RepeatPattern::Weekly,
    ), $admin, EventOrigin::Manual);
}

it('records synced 2, failed 0 and exit code 0 after a healthy sync, keeps the output line and shows OK', function () {
    publishOpsSeries($this->admin, 'Repair Café');
    publishOpsSeries($this->admin, 'Knit and Natter');

    $this->artisan('calendar:sync-occurrences')
        ->expectsOutputToContain('Synced 2 series, 0 failed.')
        ->assertExitCode(0);

    $beat = Heartbeat::find(Heartbeat::CALENDAR_SYNC);
    expect($beat)->not->toBeNull()
        ->and($beat->meta)->toMatchArray(['synced' => 2, 'failed' => 0, 'failed_ids' => [], 'exit_code' => 0])
        ->and(app(HealthReport::class)->calendarSync()->state)->toBe(HealthState::Ok);

    actingAs($this->admin)
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Daily calendar sync', 'OK', '2 series synced, 0 failed']);
});

it('records the failed id and shows Failed when one stored rule is invalid', function () {
    $broken = publishOpsSeries($this->admin, 'Broken');
    publishOpsSeries($this->admin, 'Healthy');
    DB::table('events')->where('id', $broken->id)->update(['recurrence_rule' => 'FREQ=BOGUS']);

    $this->artisan('calendar:sync-occurrences')
        ->expectsOutputToContain('Synced 1 series, 1 failed.')
        ->expectsOutputToContain("Failed event ids: {$broken->id}")
        ->assertExitCode(1);

    $meta = Heartbeat::find(Heartbeat::CALENDAR_SYNC)?->meta;
    expect($meta)->toMatchArray(['synced' => 1, 'failed' => 1, 'failed_ids' => [$broken->id], 'exit_code' => 1])
        ->and(app(HealthReport::class)->calendarSync()->state)->toBe(HealthState::Failed);

    actingAs($this->admin)
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Daily calendar sync', 'Failed', '1 series synced, 1 failed']);
});

it('shows the sync as Stale 26 hours and 1 second after the last run', function () {
    OpsFixtures::beatAgo(Heartbeat::CALENDAR_SYNC, 93601, ['synced' => 2, 'failed' => 0, 'failed_ids' => [], 'exit_code' => 0]);

    expect(app(HealthReport::class)->calendarSync()->state)->toBe(HealthState::Stale);

    actingAs($this->admin)
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Daily calendar sync', 'Stale', 'Runs daily at 03:10 (UK time)']);
});

it('shows Not run yet when the sync never ran', function () {
    expect(app(HealthReport::class)->calendarSync()->state)->toBe(HealthState::NotRunYet);

    actingAs($this->admin)
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Daily calendar sync', 'Not run yet']);
});
