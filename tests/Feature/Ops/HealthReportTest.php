<?php

use App\Enums\HealthState;
use App\Filament\Pages\SystemHealth;
use App\Models\Heartbeat;
use App\Ops\HealthReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20T09:00:00Z'));
});

// Livewire keeps "a component rendered this request" in a static that
// outlives the test; reset it so later public-page tests see no injected script.
afterEach(fn () => Livewire::flushState());

it('shows the queue worker as OK at 600 s, Stale at 601 s and Never seen with no heartbeat', function (?int $age, HealthState $state) {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 10);
    if ($age !== null) {
        OpsFixtures::beatAgo(Heartbeat::QUEUE, $age, ['latency_seconds' => 1]);
    }

    expect(app(HealthReport::class)->queue()->state)->toBe($state);

    actingAs(OpsFixtures::admin())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Queue worker', $state->label(), 'Expected every 5 minutes']);
})->with([
    '600 s' => [600, HealthState::Ok],
    '601 s' => [601, HealthState::Stale],
    'no row' => [null, HealthState::NeverSeen],
]);

it('shows how many jobs are waiting and the age of the oldest', function () {
    foreach ([5, 12] as $minutesAgo) {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    $report = app(HealthReport::class);
    expect($report->pendingJobs())->toBe(2)
        ->and($report->oldestPendingAgeSeconds())->toBe(720);

    actingAs(OpsFixtures::admin())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSee('Jobs waiting: 2')
        ->assertSee('12 minutes');
});

it('displays heartbeats either side of the October clock change in UK time', function (string $utc) {
    $this->travelTo(CarbonImmutable::parse('2026-10-25T01:31:00Z'));
    DB::table('heartbeats')->insert(['name' => Heartbeat::SCHEDULER, 'last_seen_at' => $utc]);

    expect(app(HealthReport::class)->scheduler()->lastSeenLondon())->toBe('Sun 25 Oct, 1:30am');

    actingAs(OpsFixtures::admin())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSee('Sun 25 Oct, 1:30am');
})->with([
    'BST, before the change' => ['2026-10-25 00:30:00+00'],
    'GMT, after the change' => ['2026-10-25 01:30:00+00'],
]);

it('treats a heartbeat in the future as age 0 and OK', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, -300);

    $check = app(HealthReport::class)->scheduler();

    expect($check->state)->toBe(HealthState::Ok)
        ->and($check->ageSeconds)->toBe(0);
});

it('returns the failed job count as the navigation badge, or null with none', function () {
    expect(SystemHealth::getNavigationBadge())->toBeNull();

    OpsFixtures::failedJob(120);
    OpsFixtures::failedJob(60);

    expect(SystemHealth::getNavigationBadge())->toBe('2')
        ->and(SystemHealth::getNavigationBadgeColor())->toBe('danger')
        ->and(app(HealthReport::class)->failedJobCount())->toBe(2);
});

it('exits 0 from ops:health with every check OK and calendar-sync not run yet as a warning', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);

    $this->artisan('ops:health')
        ->expectsOutputToContain('scheduler: OK')
        ->expectsOutputToContain('queue: OK')
        ->expectsOutputToContain('calendar-sync: NOT RUN YET (warning)')
        ->expectsOutput('failed jobs: 0')
        ->expectsOutput('jobs waiting: 0')
        ->expectsOutput('HEALTHY')
        ->assertExitCode(0);
});

it('exits 1 from ops:health with failed jobs: 1 when one failed job exists', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);
    OpsFixtures::failedJob();

    $this->artisan('ops:health')
        ->expectsOutput('failed jobs: 1')
        ->expectsOutput('UNHEALTHY')
        ->assertExitCode(1);
});

it('exits 1 from ops:health when the queue worker is stale even if the scheduler is fresh', function () {
    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 601, ['latency_seconds' => 1]);

    $this->artisan('ops:health')
        ->expectsOutputToContain('queue: STALE')
        ->expectsOutput('UNHEALTHY')
        ->assertExitCode(1);
});

describe('submission spam check (T-02-31)', function () {
    it('lists the spam check after the existing entries', function () {
        expect(array_map(fn ($check) => $check->key, app(HealthReport::class)->all()))
            ->toBe(['scheduler', 'queue', 'calendar-sync', 'spam-check']);
    });

    it('fails in production when either Turnstile key is missing', function (?string $siteKey, ?string $secret) {
        $this->app['env'] = 'production';
        config()->set('services.turnstile.site_key', $siteKey);
        config()->set('services.turnstile.secret', $secret);

        $check = app(HealthReport::class)->spamCheck();

        expect($check->key)->toBe('spam-check')
            ->and($check->title)->toBe('Submission spam check')
            ->and($check->state)->toBe(HealthState::Failed)
            ->and($check->detail)->toBe('Turnstile keys missing: the public form is resting')
            ->and($check->expectation)->toBe('Turnstile keys are set in production');
    })->with([
        'no secret' => ['site-key', ''],
        'no site key' => ['', 'secret'],
        'neither (unset)' => [null, null],
    ]);

    it('is OK in production with both keys set', function () {
        $this->app['env'] = 'production';
        config()->set('services.turnstile.site_key', 'site-key');
        config()->set('services.turnstile.secret', 'secret');

        $check = app(HealthReport::class)->spamCheck();

        expect($check->state)->toBe(HealthState::Ok)
            ->and($check->detail)->toBeNull();
    });

    it('is OK and not checked outside production, even with no keys', function () {
        config()->set('services.turnstile.site_key', '');
        config()->set('services.turnstile.secret', '');

        $check = app(HealthReport::class)->spamCheck();

        expect($check->state)->toBe(HealthState::Ok)
            ->and($check->detail)->toBe('Not checked outside production');
    });

    it('shows the entry on the System health page', function () {
        actingAs(OpsFixtures::admin())
            ->get('/admin/system-health')
            ->assertOk()
            ->assertSeeInOrder(['Submission spam check', 'OK', 'Turnstile keys are set in production'])
            ->assertSee('Not checked outside production');
    });

    it('makes ops:health unhealthy in production without keys', function () {
        OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
        OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);
        $this->app['env'] = 'production';
        config()->set('services.turnstile.site_key', '');
        config()->set('services.turnstile.secret', '');

        $this->artisan('ops:health')
            ->expectsOutputToContain('spam-check: FAILED')
            ->expectsOutput('UNHEALTHY')
            ->assertExitCode(1);
    });
});
