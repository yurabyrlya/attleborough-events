<?php

use App\Enums\HealthState;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Filament\Pages\SystemHealth;
use App\Models\Heartbeat;
use App\Models\Source;
use App\Models\SourceRun;
use App\Ops\HealthReport;
use App\Support\LondonFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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

describe('sources (SRC-08)', function () {
    it('is OK when every active source is healthy, ignoring inactive ones', function () {
        Source::factory()->ics()->active()->create();
        Source::factory()->ics()->create()->forceFill(['health' => SourceHealth::Unhealthy, 'consecutive_failures' => 7])->save();

        $check = app(HealthReport::class)->sources();

        expect($check->key)->toBe('sources')
            ->and($check->title)->toBe('Sources')
            ->and($check->state)->toBe(HealthState::Ok)
            ->and($check->detail)->toBe('1 active source, all healthy');
    });

    it('is OK with no active sources', function () {
        expect(app(HealthReport::class)->sources()->state)->toBe(HealthState::Ok)
            ->and(app(HealthReport::class)->sources()->detail)->toBe('No active sources');
    });

    it('names the degraded, unhealthy and structure-alert counts', function () {
        Source::factory()->ics()->active()->create();
        Source::factory()->ics()->active()->create()->forceFill(['health' => SourceHealth::Degraded, 'consecutive_failures' => 3])->save();
        Source::factory()->ics()->active()->count(2)->create()->each(fn (Source $source) => $source->forceFill(['health' => SourceHealth::Unhealthy, 'consecutive_failures' => 5])->save());
        Source::factory()->ics()->active()->create()->forceFill(['structure_alert_at' => now()->utc()])->save();

        $check = app(HealthReport::class)->sources();

        expect($check->state)->toBe(HealthState::Stale)
            ->and($check->detail)->toBe('1 degraded, 2 unhealthy (paused), 1 possible structure change');
    });

    it('lists the Sources line on the System health page after the other checks', function () {
        actingAs(OpsFixtures::admin())
            ->get('/admin/system-health')
            ->assertOk()
            ->assertSeeInOrder(['Submission spam check', 'Sources', 'No active sources']);
    });

    it('keeps ops:health exit 0 with an unhealthy source when the ops checks are healthy', function () {
        OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
        OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);
        Source::factory()->ics()->active()->create()->forceFill(['health' => SourceHealth::Unhealthy, 'consecutive_failures' => 5])->save();

        $this->artisan('ops:health')
            ->expectsOutputToContain('sources: STALE (warning): 1 unhealthy (paused)')
            ->expectsOutput('HEALTHY')
            ->assertExitCode(0);
    });

    it('prints the sources line as OK without a warning when all sources are healthy', function () {
        OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
        OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);
        Source::factory()->ics()->active()->create();

        $this->artisan('ops:health')
            ->expectsOutput('sources: OK')
            ->expectsOutput('HEALTHY')
            ->assertExitCode(0);
    });

    it('counts an active source not checked successfully for two intervals plus the jitter (WR-05)', function (string $frequency, string $lastSuccessUtc, bool $stale) {
        // Now: Tue 20 Oct 09:00 UTC. Daily: stale before Sun 18 Oct 08:40 UTC (2 days + 20 minutes).
        Source::factory()->ics()->active()->create(['check_frequency' => $frequency])
            ->forceFill(['last_success_at' => CarbonImmutable::parse($lastSuccessUtc)])->save();

        $check = app(HealthReport::class)->sources();

        expect($check->state)->toBe($stale ? HealthState::Stale : HealthState::Ok)
            ->and($check->detail)->toBe($stale ? '1 not checked successfully since '.LondonFormatter::shortDate(CarbonImmutable::parse($lastSuccessUtc)) : '1 active source, all healthy');
    })->with([
        'daily, 2 days 19 minutes ago' => ['daily', '2026-10-18T08:41:00Z', false],
        'daily, 2 days 21 minutes ago' => ['daily', '2026-10-18T08:39:00Z', true],
        'weekly, 10 days ago' => ['weekly', '2026-10-10T09:00:00Z', false],
        'weekly, 15 days ago' => ['weekly', '2026-10-05T09:00:00Z', true],
    ]);

    it('names a source blocked on every check since it was activated, and keeps ops:health exit 0 (WR-05)', function () {
        Sleep::fake(syncWithCarbon: true);
        Storage::fake('local');
        $source = Source::factory()->ics()->active()->create(['url' => 'https://blocked.example.org/events.ics']);
        ingestFeed('', "User-agent: *\nDisallow: /\n");

        // Blocked runs never move health: still "healthy" after three days of them.
        foreach (range(1, 3) as $ignored) {
            expect(ingestLiveRun($source)->status)->toBe(RunStatus::Blocked);
            $this->travel(1)->days();
        }

        $check = app(HealthReport::class)->sources();

        expect($source->refresh()->health)->toBe(SourceHealth::Healthy)
            ->and($check->state)->toBe(HealthState::Stale)
            ->and($check->detail)->toBe('1 never checked successfully');

        OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
        OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);

        $this->artisan('ops:health')
            ->expectsOutputToContain('sources: STALE (warning): 1 never checked successfully')
            ->expectsOutput('HEALTHY')
            ->assertExitCode(0);
    });

    it('does not count a newly activated source whose first check is only hours old (WR-05)', function () {
        $source = Source::factory()->ics()->active()->create();
        $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Activation]);
        $run->forceFill(['source_id' => $source->id, 'status' => RunStatus::Blocked, 'queued_at' => now()->subHours(3)->utc()])->save();

        expect(app(HealthReport::class)->sources()->state)->toBe(HealthState::Ok);
    });
});
