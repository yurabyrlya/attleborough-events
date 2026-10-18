<?php

use App\Enums\ReviewStatus;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

it('registers the dispatcher and the raw-body pruner with the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('ingest:dispatch-due')
        ->expectsOutputToContain('ingest:prune-raw')
        ->assertExitCode(0);
});
