<?php

use App\Enums\FetchFailureKind;
use App\Enums\HealthState;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Ingestion\RawBodyStore;
use App\Jobs\FetchSourceJob;
use App\Models\Heartbeat;
use App\Models\Source;
use App\Models\SourceRun;
use App\Ops\HealthReport;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Feature\Ops\OpsFixtures;

use function Pest\Laravel\actingAs;

/*
 * SRC-08 / SRC-09: only finished live runs move a source's health
 * (3 failures → degraded, 5 → unhealthy and skipped by the scheduler), a
 * zero-item run after items raises a structure alert, and no run outcome
 * ever touches published events. Offline: Http::fake, the Pest fake
 * HostResolver and Sleep::fake.
 */

const HEALTH_FEED_URL = 'https://health.example.org/calendar.ics';

/**
 * Serve robots.txt (allow all) and answer the feed with $feed (a response
 * or a closure taking the request).
 */
function healthServe(mixed $feed): void
{
    Http::fake(function (Request $request) use ($feed) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/robots.txt')) {
            return Http::response("User-agent: *\nDisallow:\n", 200, ['Content-Type' => 'text/plain']);
        }

        return $feed instanceof Closure ? $feed($request) : $feed;
    });
}

function healthIcs(string $body): mixed
{
    return Http::response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
}

/**
 * A calendar with $count one-off events, all inside the import window.
 */
function healthCalendar(int $count): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Health test//EN'];

    for ($i = 1; $i <= $count; $i++) {
        $day = CarbonImmutable::now('Europe/London')->addDays(3 + ($i % 40))->format('Ymd');
        $lines = [...$lines, 'BEGIN:VEVENT', "UID:health-{$i}@example.org", 'DTSTAMP:20261017T050000Z',
            "DTSTART;TZID=Europe/London:{$day}T190000", "SUMMARY:Health event {$i}", 'END:VEVENT'];
    }

    return implode("\r\n", [...$lines, 'END:VCALENDAR'])."\r\n";
}

/**
 * Queue a live run for the source and run its job synchronously.
 */
function healthRun(Source $source, RunMode $mode = RunMode::Live): SourceRun
{
    $run = new SourceRun(['mode' => $mode, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $source->id, 'status' => RunStatus::Queued, 'queued_at' => CarbonImmutable::now()->utc()])->save();

    FetchSourceJob::dispatchSync($run->id);

    test()->travel(1)->days();

    return $run->refresh();
}

/**
 * A run left running (or queued) by a killed worker, $minutes old.
 */
function healthStuckRun(Source $source, int $minutes, RunMode $mode = RunMode::Live): SourceRun
{
    $run = new SourceRun(['mode' => $mode, 'trigger' => RunTrigger::Schedule]);
    $run->forceFill([
        'source_id' => $source->id,
        'status' => RunStatus::Running,
        'queued_at' => CarbonImmutable::now()->subMinutes($minutes + 1)->utc(),
        'started_at' => CarbonImmutable::now()->subMinutes($minutes)->utc(),
    ])->save();

    return $run;
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->source = Source::factory()->ics()->active()->create([
        'name' => 'Example Community Centre',
        'url' => HEALTH_FEED_URL,
    ]);
});

afterEach(fn () => Livewire::flushState());

it('degrades a failing source at 3, pauses it at 5, keeps the published event and names it on System health', function () {
    makeOccurrence(['title' => 'Harvest Supper'], '2026-10-30 19:00');
    healthServe(Http::response('Internal error', 500));

    $states = [];
    foreach (range(1, 5) as $ignored) {
        $run = healthRun($this->source);
        expect($run->status)->toBe(RunStatus::Failed);
        $states[] = $this->source->refresh()->health;
    }

    expect($states)->toBe([
        SourceHealth::Healthy, SourceHealth::Healthy, SourceHealth::Degraded, SourceHealth::Degraded, SourceHealth::Unhealthy,
    ])->and($this->source->consecutive_failures)->toBe(5);

    // Paused: the scheduler skips it even though it is due.
    Queue::fake();
    $this->source->forceFill(['next_check_at' => CarbonImmutable::now()->subHour()->utc()])->save();
    $this->artisan('ingest:dispatch-due')->assertExitCode(0);
    Queue::assertNothingPushed();

    // SRC-09: the published event is still listed.
    $this->get('/')->assertOk()->assertSee('Harvest Supper');

    $check = app(HealthReport::class)->sources();
    expect($check->key)->toBe('sources')
        ->and($check->state)->not->toBe(HealthState::Ok)
        ->and($check->detail)->toContain('1 unhealthy (paused)');

    actingAs(OpsFixtures::admin())
        ->get('/admin/system-health')
        ->assertOk()
        ->assertSeeInOrder(['Sources', '1 unhealthy (paused)']);

    OpsFixtures::beatAgo(Heartbeat::SCHEDULER, 30);
    OpsFixtures::beatAgo(Heartbeat::QUEUE, 120, ['latency_seconds' => 1]);

    $this->artisan('ops:health')
        ->expectsOutputToContain('(warning)')
        ->expectsOutput('HEALTHY')
        ->assertExitCode(0);
});

it('makes an unhealthy source healthy again after one successful run', function () {
    $this->source->forceFill(['health' => SourceHealth::Unhealthy, 'consecutive_failures' => 5])->save();
    healthServe(healthIcs(healthCalendar(3)));

    $run = healthRun($this->source);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($this->source->refresh()->health)->toBe(SourceHealth::Healthy)
        ->and($this->source->consecutive_failures)->toBe(0);
});

it('counts three runs abandoned by the janitor as three failures', function () {
    Queue::fake();
    $this->source->forceFill(['next_check_at' => CarbonImmutable::now()->addDays(3)->utc()])->save();

    foreach (range(1, 3) as $ignored) {
        $run = healthStuckRun($this->source, 16);
        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect($run->refresh()->status)->toBe(RunStatus::Failed)
            ->and($run->failure_kind)->toBe(FetchFailureKind::Abandoned->value);
    }

    expect($this->source->refresh()->health)->toBe(SourceHealth::Degraded)
        ->and($this->source->consecutive_failures)->toBe(3);
    Queue::assertNothingPushed();
});

it('changes nothing when the janitor abandons a preview run', function () {
    Queue::fake();
    $this->source->forceFill(['next_check_at' => CarbonImmutable::now()->addDays(3)->utc()])->save();
    $run = healthStuckRun($this->source, 16, RunMode::Preview);

    $this->artisan('ingest:dispatch-due')->assertExitCode(0);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($this->source->refresh()->health)->toBe(SourceHealth::Healthy)
        ->and($this->source->consecutive_failures)->toBe(0);
});

it('counts a run the job abandons on an unexpected error as a failure', function () {
    healthServe(healthIcs(healthCalendar(2)));
    $this->source->forceFill(['consecutive_failures' => 2])->save();
    $this->app->instance(RawBodyStore::class, new class extends RawBodyStore
    {
        public function put(int $sourceId, int $runId, string $body): string
        {
            throw new RuntimeException('disk full');
        }
    });

    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $this->source->id, 'status' => RunStatus::Queued])->save();

    expect(fn () => FetchSourceJob::dispatchSync($run->id))->toThrow(RuntimeException::class, 'disk full');

    expect($run->refresh()->failure_kind)->toBe(FetchFailureKind::Abandoned->value)
        ->and($this->source->refresh()->consecutive_failures)->toBe(3)
        ->and($this->source->health)->toBe(SourceHealth::Degraded);
});
