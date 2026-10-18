<?php

use App\Enums\EventStatus;
use App\Enums\FetchFailureKind;
use App\Enums\HealthState;
use App\Enums\ReviewStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Ingestion\RawBodyStore;
use App\Jobs\FetchSourceJob;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Heartbeat;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\Venue;
use App\Ops\HealthReport;
use App\Publishing\Actions\ApproveDraft;
use App\Review\DraftPublishability;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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

/**
 * Serve the feed according to $state['mode']: ok (calendar $state['body']
 * with an ETag), fail (500), blocked (robots.txt disallows everything) or
 * not_modified (304 when the ETag is sent back).
 */
function healthServeModes(ArrayObject $state): void
{
    Http::fake(function (Request $request) use ($state) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/robots.txt')) {
            $rules = $state['mode'] === 'blocked' ? "User-agent: *\nDisallow: /\n" : "User-agent: *\nDisallow:\n";

            return Http::response($rules, 200, ['Content-Type' => 'text/plain']);
        }

        return match ($state['mode']) {
            'fail' => Http::response('Internal error', 500),
            'not_modified' => $request->hasHeader('If-None-Match', '"v1"')
                ? Http::response('', 304, ['ETag' => '"v1"'])
                : Http::response($state['body'], 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'ETag' => '"v1"']),
            default => Http::response($state['body'], 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'ETag' => '"v1"']),
        };
    });
}

/**
 * Every row of the tables a run must never change (SRC-09), as JSON.
 *
 * @return array<string, string>
 */
function healthSnapshot(): array
{
    return collect(['events', 'event_occurrences', 'event_drafts', 'source_items', 'event_sources'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])
        ->all();
}

/**
 * Ingest five events from the source and approve one of them, so a
 * published event came from an earlier run. Later modes go through the
 * same $state: a second Http::fake would append, and the first one wins.
 */
function healthPublishFromRun(Source $source, ArrayObject $state): Event
{
    $state['mode'] = 'ok';
    $state['body'] = healthCalendar(5);
    healthServeModes($state);
    expect(healthRun($source)->status)->toBe(RunStatus::Succeeded);

    // The latest one: healthRun() moves the clock a day per run.
    $draft = EventDraft::query()->whereNotNull('source_item_id')->orderByDesc('start_date')->orderBy('id')->firstOrFail();
    $draft->forceFill([
        'venue_id' => Venue::factory()->create()->id,
        'category_id' => Category::query()->orderBy('id')->value('id'),
    ])->save();
    expect(app(DraftPublishability::class)->blockers($draft->refresh()))->toBe([]);

    return app(ApproveDraft::class)($draft, OpsFixtures::admin());
}

describe('health and completeness edges (SRC-08)', function () {
    it('maps consecutive failures to health at the 3 and 5 boundaries', function (int $failures, SourceHealth $health) {
        healthServeModes(new ArrayObject(['mode' => 'fail', 'body' => '']));

        foreach (range(1, $failures) as $ignored) {
            healthRun($this->source);
        }

        expect($this->source->refresh()->consecutive_failures)->toBe($failures)
            ->and($this->source->health)->toBe($health);
    })->with([
        '2 failures' => [2, SourceHealth::Healthy],
        '3 failures' => [3, SourceHealth::Degraded],
        '4 failures' => [4, SourceHealth::Degraded],
        '5 failures' => [5, SourceHealth::Unhealthy],
    ]);

    it('neither resets nor counts a blocked run between failures', function () {
        $state = new ArrayObject(['mode' => 'fail', 'body' => '']);
        healthServeModes($state);

        $statuses = [];
        foreach (['fail', 'fail', 'blocked', 'fail'] as $mode) {
            $state['mode'] = $mode;
            $statuses[] = healthRun($this->source)->status;
        }

        expect($statuses)->toBe([RunStatus::Failed, RunStatus::Failed, RunStatus::Blocked, RunStatus::Failed])
            ->and($this->source->refresh()->consecutive_failures)->toBe(3)
            ->and($this->source->health)->toBe(SourceHealth::Degraded);
    });

    it('resets the failure count on a not-modified run', function () {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(3)]);
        healthServeModes($state);
        healthRun($this->source);
        $this->source->refresh()->forceFill(['consecutive_failures' => 4, 'health' => SourceHealth::Degraded])->save();

        $state['mode'] = 'not_modified';
        $run = healthRun($this->source);

        expect($run->status)->toBe(RunStatus::NotModified)
            ->and($run->is_complete)->toBeFalse()
            ->and($this->source->refresh()->consecutive_failures)->toBe(0)
            ->and($this->source->health)->toBe(SourceHealth::Healthy);
    });

    it('raises a structure alert for zero items after a run with items, and clears it when items return', function () {
        $state = new ArrayObject(['mode' => 'ok', 'body' => sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics')]);
        healthServeModes($state);
        $first = healthRun($this->source);

        $state['body'] = healthCalendar(0);
        $empty = healthRun($this->source);
        $alertAt = $this->source->refresh()->structure_alert_at;

        expect($first->items_found)->toBe(64)
            ->and($first->is_complete)->toBeTrue()
            ->and($empty->status)->toBe(RunStatus::Succeeded)
            ->and($empty->structure_alert)->toBeTrue()
            ->and($empty->is_complete)->toBeFalse()
            ->and($alertAt)->not->toBeNull()
            ->and($this->source->health)->toBe(SourceHealth::Healthy)
            ->and(app(HealthReport::class)->sources()->detail)->toBe('1 possible structure change');

        $state['body'] = healthCalendar(40);
        $back = healthRun($this->source);

        expect($back->structure_alert)->toBeFalse()
            ->and($this->source->refresh()->structure_alert_at)->toBeNull();
    });

    it('raises no structure alert for zero items after zero items, or on a first-ever run', function () {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(0)]);
        healthServeModes($state);

        $firstEver = healthRun($this->source);
        $again = healthRun($this->source);

        expect($firstEver->structure_alert)->toBeFalse()
            ->and($again->structure_alert)->toBeFalse()
            ->and($firstEver->is_complete)->toBeFalse()
            ->and($again->is_complete)->toBeFalse()
            ->and($this->source->refresh()->structure_alert_at)->toBeNull();
    });

    it('marks a run complete only at half or more of the previous complete run', function (int $items, bool $complete) {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(64)]);
        healthServeModes($state);
        expect(healthRun($this->source)->is_complete)->toBeTrue();

        $state['body'] = healthCalendar($items);
        $run = healthRun($this->source);

        expect($run->items_found)->toBe($items)
            ->and($run->is_complete)->toBe($complete)
            ->and($run->structure_alert)->toBeFalse();
    })->with([
        '30 of 64' => [30, false],
        '31 of 64' => [31, false],
        '32 of 64 (exactly half)' => [32, true],
        '40 of 64' => [40, true],
    ]);

    it('compares with the previous complete run, not an incomplete one in between', function () {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(64)]);
        healthServeModes($state);
        healthRun($this->source);

        $state['body'] = healthCalendar(20);
        expect(healthRun($this->source)->is_complete)->toBeFalse();

        $state['body'] = healthCalendar(30);
        expect(healthRun($this->source)->is_complete)->toBeFalse();
    });

    it('accepts a lastingly smaller feed as complete on the third steady run, so missing detection recovers (WR-02)', function () {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(64)]);
        healthServeModes($state);
        expect(healthRun($this->source)->is_complete)->toBeTrue();

        $complete = [];
        foreach ([20, 22, 19, 20] as $items) {
            $state['body'] = healthCalendar($items);
            $complete[] = healthRun($this->source)->is_complete;
        }

        // 20 and 22 are short of half of 64; the third steady run (19, within
        // 20% of 20 and 22) becomes the new reference, and 20 then compares with 19.
        expect($complete)->toBe([false, false, true, true]);
    });

    it('keeps a feed that keeps shrinking, or a one-off short feed, incomplete (WR-02)', function () {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(64)]);
        healthServeModes($state);
        healthRun($this->source);

        $complete = [];
        foreach ([30, 20, 14, 64, 10, 64] as $items) {
            $state['body'] = healthCalendar($items);
            $complete[] = healthRun($this->source)->is_complete;
        }

        expect($complete)->toBe([false, false, false, true, false, true]);
    });

    it('says on the source page that missing-item detection is paused, until the feed settles (WR-02)', function () {
        $this->source->forceFill(['supports_missing_detection' => true])->save();
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(64)]);
        healthServeModes($state);
        healthRun($this->source);
        actingAs(OpsFixtures::admin());

        $page = fn () => Livewire::test(EditSource::class, ['record' => $this->source->getRouteKey()]);
        $page()->assertDontSee('Missing-item detection paused');

        $state['body'] = healthCalendar(20);
        healthRun($this->source);
        healthRun($this->source);

        $page()->assertSee('Missing-item detection paused')
            ->assertSee('The last complete check was on Sat 17 Oct.')
            ->assertSee('Detection resumes once the feed stays at its new size for 3 checks in a row.');

        healthRun($this->source);

        $page()->assertDontSee('Missing-item detection paused');
    });

    it('never marks a failed or blocked run complete', function () {
        $state = new ArrayObject(['mode' => 'fail', 'body' => '']);
        healthServeModes($state);

        expect(healthRun($this->source)->is_complete)->toBeFalse();

        $state['mode'] = 'blocked';
        expect(healthRun($this->source)->is_complete)->toBeFalse();
    });
});

describe('failure isolation (SRC-09)', function () {
    it('leaves every event, occurrence, draft and item unchanged on each repeated failing run', function () {
        $state = new ArrayObject;
        $event = healthPublishFromRun($this->source, $state);
        $before = healthSnapshot();

        $state['mode'] = 'fail';
        expect(healthRun($this->source)->status)->toBe(RunStatus::Failed)
            ->and(healthSnapshot())->toBe($before);

        expect(healthRun($this->source)->status)->toBe(RunStatus::Failed)
            ->and(healthSnapshot())->toBe($before);

        $state['mode'] = 'blocked';
        expect(healthRun($this->source)->status)->toBe(RunStatus::Blocked)
            ->and(healthSnapshot())->toBe($before);

        $this->get('/')->assertOk()->assertSee($event->title);
    });

    it('keeps the published event visible after five failed runs pause the source', function () {
        $state = new ArrayObject;
        $event = healthPublishFromRun($this->source, $state);
        $before = healthSnapshot();
        $state['mode'] = 'fail';

        foreach (range(1, 5) as $ignored) {
            healthRun($this->source);
        }

        expect($this->source->refresh()->health)->toBe(SourceHealth::Unhealthy)
            ->and(healthSnapshot())->toBe($before)
            ->and(Event::query()->findOrFail($event->id)->status)->toBe(EventStatus::Published);
        $this->get('/')->assertOk()->assertSee($event->title);
        $this->get('/events/'.$event->slug)->assertOk();
    });

    it('keeps an approval intact when a failed run is recorded in the same open transaction', function (bool $runFirst) {
        $state = new ArrayObject(['mode' => 'ok', 'body' => healthCalendar(5)]);
        healthServeModes($state);
        healthRun($this->source);

        $draft = EventDraft::query()->whereNotNull('source_item_id')->orderBy('start_date')->orderBy('id')->firstOrFail();
        $draft->forceFill([
            'venue_id' => Venue::factory()->create()->id,
            'category_id' => Category::query()->orderBy('id')->value('id'),
        ])->save();
        $admin = OpsFixtures::admin();
        $state['mode'] = 'fail';

        $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
        $run->forceFill(['source_id' => $this->source->id, 'status' => RunStatus::Queued])->save();

        $event = DB::transaction(function () use ($runFirst, $run, $draft, $admin): Event {
            if ($runFirst) {
                FetchSourceJob::dispatchSync($run->id);
            }

            $event = app(ApproveDraft::class)($draft, $admin);
            $afterApproval = healthSnapshot();

            if (! $runFirst) {
                FetchSourceJob::dispatchSync($run->id);
            }

            expect(healthSnapshot())->toBe($afterApproval);

            return $event;
        });

        expect($run->refresh()->status)->toBe(RunStatus::Failed)
            ->and($this->source->refresh()->consecutive_failures)->toBe(1)
            ->and($draft->refresh()->review_status)->toBe(ReviewStatus::Approved)
            ->and($draft->event_id)->toBe($event->id)
            ->and(Event::query()->findOrFail($event->id)->status)->toBe(EventStatus::Published);
        $this->get('/')->assertOk()->assertSee($event->title);
    })->with([
        'run recorded before the approval' => [true],
        'run recorded after the approval' => [false],
    ]);
});
