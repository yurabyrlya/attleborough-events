<?php

use App\Enums\FetchFailureKind;
use App\Enums\ReviewStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\FetchSourceJob;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * SRC-04 / D-02: the scheduler's ingest:dispatch-due starts every due active
 * source and moves its next check to the next early-morning London slot.
 * Offline: Http::fake, the Pest fake HostResolver and Sleep::fake.
 */

const DISPATCH_BAPTIST_ROBOTS_URL = 'https://www.attleborough.church/robots.txt';

const DISPATCH_BAPTIST_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

function dispatchFakeBaptist(): void
{
    Http::fake([
        DISPATCH_BAPTIST_ROBOTS_URL => Http::response("User-agent: *\nCrawl-delay: 10\nDisallow: /Admin/\n", 200, ['Content-Type' => 'text/plain']),
        DISPATCH_BAPTIST_ICS_URL => Http::response(
            sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'),
            200,
            ['Content-Type' => 'text/calendar; charset=utf-8'],
        ),
    ]);
}

/**
 * The stored next_check_at as a UTC wall-clock string, read with raw SQL.
 */
function dispatchStoredNextCheck(Source $source): ?string
{
    $row = DB::selectOne(
        "select to_char(next_check_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as n from sources where id = ?",
        [$source->id],
    );

    return $row->n;
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    $this->seed(CategorySeeder::class);
});

it('starts a due active source on schedule, creates its drafts and moves the next check to tomorrow 05:30 London plus jitter', function () {
    freezeLondon('2026-10-17 06:00:00');
    dispatchFakeBaptist();

    $source = Source::factory()->ics()->active()->create([
        'name' => 'Attleborough Baptist Church',
        'url' => DISPATCH_BAPTIST_ICS_URL,
    ]);

    $this->artisan('ingest:dispatch-due')->assertExitCode(0);

    // The worker picks the queued job off the real database queue.
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true])
        ->assertExitCode(0);

    $run = SourceRun::query()->where('source_id', $source->id)->sole();
    $jitter = ($source->id * 7) % 20;
    // 18 Oct 2026 is BST: 05:30 London is 04:30 UTC.
    $expected = CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC')->addMinutes($jitter)->format('Y-m-d H:i:s');

    expect($run->trigger)->toBe(RunTrigger::Schedule)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->items_new)->toBeGreaterThan(0)
        ->and($run->queued_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-17 05:00:00')
        ->and(EventDraft::query()->where('source_id', $source->id)->where('review_status', ReviewStatus::PendingReview)->count())
        ->toBe($run->drafts_created)
        ->and($run->drafts_created)->toBeGreaterThan(0)
        ->and(dispatchStoredNextCheck($source))->toBe($expected);
});

/**
 * A run in an active state for the janitor tests.
 */
function dispatchActiveRun(Source $source, RunStatus $status, CarbonImmutable $since): SourceRun
{
    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Schedule]);
    $run->forceFill([
        'source_id' => $source->id,
        'status' => $status,
        'queued_at' => $since->utc(),
        'started_at' => $status === RunStatus::Running ? $since->utc() : null,
    ])->save();

    return $run;
}

/**
 * Source ids of the FetchSourceJobs pushed, in push order.
 *
 * @return list<int>
 */
function dispatchPushedSourceIds(): array
{
    return Queue::pushed(FetchSourceJob::class)
        ->map(fn (FetchSourceJob $job) => SourceRun::query()->whereKey($job->runId)->value('source_id'))
        ->values()
        ->all();
}

describe('with a fake queue', function () {
    beforeEach(function () {
        Queue::fake();
        freezeLondon('2026-10-17 06:00:00');
        $this->now = CarbonImmutable::now();
    });

    it('marks stale running and queued runs abandoned and leaves fresh ones alone', function () {
        [$a, $b, $c, $d] = Source::factory()->ics()->count(4)->create();

        $staleRunning = dispatchActiveRun($a, RunStatus::Running, $this->now->subMinutes(16));
        $freshRunning = dispatchActiveRun($b, RunStatus::Running, $this->now->subMinutes(14));
        $staleQueued = dispatchActiveRun($c, RunStatus::Queued, $this->now->subMinutes(121));
        $freshQueued = dispatchActiveRun($d, RunStatus::Queued, $this->now->subMinutes(119));

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        foreach ([$staleRunning, $staleQueued] as $run) {
            $run->refresh();
            expect($run->status)->toBe(RunStatus::Failed)
                ->and($run->failure_kind)->toBe(FetchFailureKind::Abandoned->value)
                ->and($run->error_message)->toContain('abandoned')
                ->and($run->finished_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-17 05:00:00');
        }

        expect($freshRunning->refresh()->status)->toBe(RunStatus::Running)
            ->and($freshRunning->failure_kind)->toBeNull()
            ->and($freshQueued->refresh()->status)->toBe(RunStatus::Queued);
    });

    it('starts a due source in the same pass once the janitor has freed its slot', function () {
        $source = Source::factory()->ics()->active()->create();
        $stale = dispatchActiveRun($source, RunStatus::Running, $this->now->subMinutes(20));

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect($stale->refresh()->status)->toBe(RunStatus::Failed)
            ->and(SourceRun::query()->where('source_id', $source->id)->where('status', RunStatus::Queued)->count())->toBe(1);
        Queue::assertPushed(FetchSourceJob::class, 1);
    });

    it('treats next_check_at equal to now as due and one second later as not due', function () {
        $atNow = Source::factory()->ics()->active()->create();
        $atNow->forceFill(['next_check_at' => $this->now->utc()])->save();
        $justLater = Source::factory()->ics()->active()->create();
        $justLater->forceFill(['next_check_at' => $this->now->addSecond()->utc()])->save();

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect(dispatchPushedSourceIds())->toBe([$atNow->id])
            ->and(SourceRun::query()->where('source_id', $justLater->id)->exists())->toBeFalse()
            ->and($justLater->refresh()->next_check_at?->equalTo($this->now->addSecond()))->toBeTrue();
    });

    it('never starts inactive or unhealthy sources', function () {
        $inactive = Source::factory()->ics()->create();
        $unhealthy = Source::factory()->ics()->active()->create();
        $unhealthy->forceFill(['health' => 'unhealthy'])->save();
        $degraded = Source::factory()->ics()->active()->create();
        $degraded->forceFill(['health' => 'degraded'])->save();

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect(dispatchPushedSourceIds())->toBe([$degraded->id])
            ->and($inactive->refresh()->next_check_at)->toBeNull()
            ->and($unhealthy->refresh()->next_check_at)->toBeNull();
    });

    it('creates no runs and dispatches nothing when no source is due', function () {
        $later = Source::factory()->ics()->active()->create();
        $later->forceFill(['next_check_at' => $this->now->addHours(3)->utc()])->save();

        $this->artisan('ingest:dispatch-due')
            ->expectsOutputToContain('Started 0 runs')
            ->assertExitCode(0);

        expect(SourceRun::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('exits 0 with no sources at all', function () {
        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect(SourceRun::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('starts due sources in next_check_at order, then id', function () {
        $first = Source::factory()->ics()->active()->create();
        $tieLow = Source::factory()->ics()->active()->create();
        $tieHigh = Source::factory()->ics()->active()->create();
        $never = Source::factory()->ics()->active()->create();

        $first->forceFill(['next_check_at' => $this->now->subMinutes(5)->utc()])->save();
        $tieHigh->forceFill(['next_check_at' => $this->now->subMinutes(30)->utc()])->save();
        $tieLow->forceFill(['next_check_at' => $this->now->subMinutes(30)->utc()])->save();

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        // Never-scheduled (null) sources first, then oldest due, ties by id.
        expect(dispatchPushedSourceIds())->toBe([$never->id, $tieLow->id, $tieHigh->id, $first->id]);
    });

    it('skips a source that is already being checked but still moves its next check', function () {
        $source = Source::factory()->ics()->active()->create();
        $manual = dispatchActiveRun($source, RunStatus::Running, $this->now->subMinutes(2));

        $this->artisan('ingest:dispatch-due')
            ->expectsOutputToContain('1 already active')
            ->assertExitCode(0);

        expect(SourceRun::query()->where('source_id', $source->id)->count())->toBe(1)
            ->and($manual->refresh()->status)->toBe(RunStatus::Running)
            ->and($source->refresh()->next_check_at)->not->toBeNull();
        Queue::assertNothingPushed();
    });

    it('only starts the sources named with --source', function () {
        $wanted = Source::factory()->ics()->active()->create();
        Source::factory()->ics()->active()->create();

        $this->artisan('ingest:dispatch-due', ['--source' => [$wanted->id]])->assertExitCode(0);

        expect(dispatchPushedSourceIds())->toBe([$wanted->id]);
    });

    it('stores the next check at 05:30 UTC on the day BST ends', function () {
        freezeLondon('2026-10-24 23:30:00');
        $source = Source::factory()->ics()->active()->create();
        $jitter = ($source->id * 7) % 20;

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect(dispatchStoredNextCheck($source))
            ->toBe(CarbonImmutable::parse('2026-10-25 05:30:00', 'UTC')->addMinutes($jitter)->format('Y-m-d H:i:s'));
    });

    it('stores the next check at 04:30 UTC on the day BST starts', function () {
        freezeLondon('2027-03-27 23:30:00');
        $source = Source::factory()->ics()->active()->create();
        $jitter = ($source->id * 7) % 20;

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect(dispatchStoredNextCheck($source))
            ->toBe(CarbonImmutable::parse('2027-03-28 04:30:00', 'UTC')->addMinutes($jitter)->format('Y-m-d H:i:s'));
    });

    it('stores the next check seven days ahead for a weekly source', function () {
        $source = Source::factory()->ics()->active()->create(['check_frequency' => 'weekly']);
        $jitter = ($source->id * 7) % 20;

        $this->artisan('ingest:dispatch-due')->assertExitCode(0);

        expect(dispatchStoredNextCheck($source))
            ->toBe(CarbonImmutable::parse('2026-10-24 04:30:00', 'UTC')->addMinutes($jitter)->format('Y-m-d H:i:s'));
    });
});

it('registers the dispatcher and the raw-body pruner with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('ingest:dispatch-due')
        ->expectsOutputToContain('ingest:prune-raw')
        ->assertExitCode(0);
});
