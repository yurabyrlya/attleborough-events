<?php

use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceHealth;
use App\Ingestion\RawBodyStore;
use App\Ingestion\RunAlreadyActive;
use App\Ingestion\RunLauncher;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * D-01 / D-24: a test run (preview) fetches and classifies a source without
 * writing items, drafts or health; "Create drafts" activates the source and
 * reuses the preview's body when it is under an hour old. Offline:
 * Http::fake, the Pest fake HostResolver and Sleep::fake.
 */

const PREVIEW_BAPTIST_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

/**
 * Serve the Baptist robots.txt and feed ($feedStatus 200: the captured
 * calendar, or $body when given). Returns a counter of feed requests.
 */
function previewServe(int $feedStatus = 200, ?string $body = null): ArrayObject
{
    $counter = new ArrayObject(['feed' => 0]);

    Http::fake(function (Request $request) use ($counter, $feedStatus, $body) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/robots.txt')) {
            return Http::response("User-agent: *\nCrawl-delay: 10\nDisallow: /Admin/\n", 200, ['Content-Type' => 'text/plain']);
        }

        $counter['feed']++;

        return $feedStatus === 200
            ? Http::response($body ?? sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'), 200, ['Content-Type' => 'text/calendar; charset=utf-8'])
            : Http::response('Server error', $feedStatus);
    });

    return $counter;
}

/**
 * Run the queued job on the real database queue.
 */
function previewWork(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true])->assertExitCode(0);
}

/**
 * @return array{items: int, drafts: int}
 */
function previewCounts(): array
{
    return ['items' => SourceItem::query()->count(), 'drafts' => EventDraft::query()->count()];
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->source = Source::factory()->ics()->create([
        'name' => 'Attleborough Baptist Church',
        'url' => PREVIEW_BAPTIST_ICS_URL,
    ]);
    $this->launcher = app(RunLauncher::class);
});

it('previews an inactive source without writing items, drafts or health', function () {
    previewServe();
    $this->source->forceFill(['consecutive_failures' => 2])->save();

    $run = $this->launcher->testRun($this->source);

    expect($run->mode)->toBe(RunMode::Preview)
        ->and($run->trigger)->toBe(RunTrigger::Manual)
        ->and($run->status)->toBe(RunStatus::Queued);

    previewWork();
    $run->refresh();
    $source = $this->source->refresh();
    $preview = $run->preview;
    $rows = collect($preview['rows']);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->drafts_created)->toBe(0)
        ->and($run->is_complete)->toBeFalse()
        ->and($run->raw_path)->not->toBeNull()
        ->and($preview['would_create'])->toBeGreaterThan(0)
        ->and($preview['would_create'])->toBe($rows->where('verdict', 'new')->count())
        ->and($preview['skipped']['far_future'])->toBeGreaterThan(0)
        ->and($preview['skipped']['far_future'])->toBe($rows->where('reason', 'far_future')->count())
        // jsonb orders object keys itself: check the set, not the order.
        ->and($preview['skipped'])->toHaveCount(4)->toHaveKeys(['past', 'far_future', 'rule', 'parse_error'])
        ->and($rows)->toHaveCount(64)
        ->and(previewCounts())->toBe(['items' => 0, 'drafts' => 0])
        ->and($source->is_active)->toBeFalse()
        ->and($source->health)->toBe(SourceHealth::Healthy)
        ->and($source->consecutive_failures)->toBe(2)
        ->and($source->last_run_at)->toBeNull()
        ->and($source->etag)->toBeNull()
        ->and($source->next_check_at)->toBeNull();

    foreach ($rows->where('verdict', 'new') as $row) {
        expect($row)->toHaveKeys(['title', 'starts', 'verdict', 'reason'])
            ->and($row['title'])->toBeString()->not->toBe('')
            ->and($row['starts'])->toMatch('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/')
            ->and($row['reason'])->toBeNull();
    }

    expect($rows->where('verdict', 'skipped')->pluck('reason')->unique()->values()->all())
        ->each->toBeIn(['past', 'far_future', 'rule', 'parse_error']);
});

it('keeps at most 200 preview rows while counting every item', function () {
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Preview test//EN'];
    for ($i = 1; $i <= 250; $i++) {
        $day = CarbonImmutable::now('Europe/London')->addDays(3 + ($i % 40))->format('Ymd');
        $lines = [...$lines, 'BEGIN:VEVENT', "UID:preview-{$i}@example.org", 'DTSTAMP:20261017T050000Z',
            "DTSTART;TZID=Europe/London:{$day}T190000", "SUMMARY:Preview event {$i}", 'END:VEVENT'];
    }
    previewServe(body: implode("\r\n", [...$lines, 'END:VCALENDAR'])."\r\n");

    $run = $this->launcher->testRun($this->source);
    previewWork();
    $run->refresh();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->items_found)->toBe(250)
        ->and($run->preview['would_create'])->toBe(250)
        ->and($run->preview['rows'])->toHaveCount(200)
        ->and(previewCounts())->toBe(['items' => 0, 'drafts' => 0]);
});

it('records a failed preview without changing health', function () {
    previewServe(500);

    $run = $this->launcher->testRun($this->source);
    previewWork();

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and($run->preview)->toBeNull()
        ->and($this->source->refresh()->consecutive_failures)->toBe(0)
        ->and($this->source->health)->toBe(SourceHealth::Healthy)
        ->and($this->source->last_run_at)->toBeNull();
});

it('creates drafts from the preview body without a second request when the preview is under an hour old', function () {
    $requests = previewServe();
    $preview = $this->launcher->testRun($this->source);
    previewWork();
    $preview->refresh();

    $this->travel(30)->minutes();
    $run = $this->launcher->createDrafts($this->source);
    $source = $this->source->refresh();

    expect($run->mode)->toBe(RunMode::Live)
        ->and($run->trigger)->toBe(RunTrigger::Activation)
        ->and($source->is_active)->toBeTrue()
        ->and($source->next_check_at)->not->toBeNull()
        ->and($source->next_check_at->greaterThan(CarbonImmutable::now()))->toBeTrue();

    previewWork();
    $run->refresh();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->drafts_created)->toBe($preview->preview['would_create'])
        ->and(EventDraft::query()->count())->toBe($preview->preview['would_create'])
        ->and($run->raw_sha256)->toBe($preview->raw_sha256)
        ->and(app(RawBodyStore::class)->get($source->id, $run->id))->not->toBeNull()
        ->and($requests['feed'])->toBe(1);
});

it('fetches the feed again when the preview is over an hour old', function () {
    $requests = previewServe();
    $this->launcher->testRun($this->source);
    previewWork();

    $this->travel(61)->minutes();
    $run = $this->launcher->createDrafts($this->source);
    previewWork();

    expect($run->refresh()->status)->toBe(RunStatus::Succeeded)
        ->and($run->drafts_created)->toBeGreaterThan(0)
        ->and($requests['feed'])->toBe(2);
});

it('fetches the feed again when the source address changed after the preview', function () {
    $requests = previewServe();
    $this->launcher->testRun($this->source);
    previewWork();

    $this->travel(10)->minutes();
    $this->source->update(['url' => PREVIEW_BAPTIST_ICS_URL.'?v=2']);
    $this->launcher->createDrafts($this->source);
    previewWork();

    expect($requests['feed'])->toBe(2);
});

it('shows known items as unchanged in a later preview', function () {
    previewServe();
    $this->launcher->testRun($this->source);
    previewWork();
    $this->travel(5)->minutes();
    $this->launcher->createDrafts($this->source);
    previewWork();
    $counts = previewCounts();

    $this->travel(5)->minutes();
    $run = $this->launcher->testRun($this->source);
    previewWork();
    $rows = collect($run->refresh()->preview['rows']);

    expect($run->preview['would_create'])->toBe(0)
        ->and($rows->where('verdict', 'unchanged')->count())->toBe($counts['items'])
        ->and(previewCounts())->toBe($counts);
});

it('refuses a test run or Create drafts while a run is active, leaving the source inactive', function () {
    previewServe();
    $this->launcher->testRun($this->source);

    expect(fn () => $this->launcher->testRun($this->source))->toThrow(RunAlreadyActive::class)
        ->and(fn () => $this->launcher->createDrafts($this->source))->toThrow(RunAlreadyActive::class)
        ->and($this->source->refresh()->is_active)->toBeFalse()
        ->and($this->source->next_check_at)->toBeNull()
        ->and(SourceRun::query()->count())->toBe(1);
});
