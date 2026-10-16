<?php

use App\Models\Heartbeat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T09:00:00Z'));
});

// Livewire keeps "a component rendered this request" in a static that
// outlives the test; reset it so later public-page tests see no injected script.
afterEach(fn () => Livewire::flushState());

/**
 * @return array{n: int, s: string}
 */
function schedulerRowSnapshot(): array
{
    $row = DB::selectOne("select count(*) as n, max(last_seen_at at time zone 'UTC') as s from heartbeats where name = 'scheduler'");

    return ['n' => (int) $row->n, 's' => substr((string) $row->s, 0, 19)];
}

it('writes one scheduler heartbeat at the frozen now when heartbeat:scheduler runs, and moves it on a second run', function () {
    $event = OpsFixtures::scheduledByDescription('heartbeat:scheduler');
    expect($event)->not->toBeNull();

    $event->run(app());
    expect(schedulerRowSnapshot())->toBe(['n' => 1, 's' => '2026-10-20 09:00:00']);

    $this->travel(90)->seconds();
    $event->run(app());

    expect(schedulerRowSnapshot())->toBe(['n' => 1, 's' => '2026-10-20 09:01:30'])
        ->and(DB::table('heartbeats')->count())->toBe(1);
});

it('schedules heartbeat:scheduler every minute', function () {
    expect(OpsFixtures::scheduledByDescription('heartbeat:scheduler')?->expression)->toBe('* * * * *');
});

it('shows the scheduler as OK on the System health page when the heartbeat is 60 seconds old', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 60);

    actingAs(OpsFixtures::admin())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSee('Scheduler')
        ->assertSee('OK')
        ->assertSee('Expected every minute');
});

it('shows the scheduler as OK at 180 s, Stale at 181 s and Never seen with no heartbeat', function (?int $age, string $label, string $absent) {
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 30, ['latency_seconds' => 1]);
    if ($age !== null) {
        OpsFixtures::beatAgo(Heartbeat::SCHEDULER, $age);
    }

    actingAs(OpsFixtures::admin())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Scheduler', $label, 'Expected every minute'])
        ->assertDontSee($absent);
})->with([
    '180 s' => [180, 'OK', 'Stale'],
    '181 s' => [181, 'Stale', 'Never seen'],
    'no row' => [null, 'Never seen', 'Stale'],
]);

it('forbids a non-admin user and redirects a guest to the login page', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    actingAs($intruder)->get('/admin/system-health')->assertForbidden();

    auth()->logout();
    get('/admin/system-health')->assertRedirect('/admin/login');
});

it('prints scheduler: OK from ops:health when fresh, and scheduler: STALE / NEVER SEEN with UNHEALTHY and exit 1 otherwise', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 30, ['latency_seconds' => 1]);
    $this->artisan('ops:health')
        ->expectsOutputToContain('scheduler: OK')
        ->expectsOutput('HEALTHY')
        ->assertExitCode(0);

    DB::table('heartbeats')->where('name', Heartbeat::SCHEDULER)->delete();
    $this->artisan('ops:health')
        ->expectsOutputToContain('scheduler: NEVER SEEN')
        ->expectsOutput('UNHEALTHY')
        ->assertExitCode(1);

    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 181);
    $this->artisan('ops:health')
        ->expectsOutputToContain('scheduler: STALE')
        ->expectsOutput('UNHEALTHY')
        ->assertExitCode(1);
});
