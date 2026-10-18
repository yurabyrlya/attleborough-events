<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Enums\SourceItemStatus;
use App\Ingestion\RawBodyStore;
use App\Jobs\FetchSourceJob;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * Success criterion 1 and the first half of 2: a queued live run of the
 * Baptist Church feed goes FetchSourceJob → HttpFetcher → IcsExtractor →
 * SourceItemRecorder → DraftWriter and leaves one pending draft per
 * in-window item; the same calendar again creates nothing. Offline:
 * Http::fake, the Pest fake HostResolver and Sleep::fake.
 */

const TRACER_BAPTIST_ROBOTS_URL = 'https://www.attleborough.church/robots.txt';

const TRACER_BAPTIST_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

function tracerFakeBaptist(array $headers = []): void
{
    Http::fake([
        TRACER_BAPTIST_ROBOTS_URL => Http::response("User-agent: *\nCrawl-delay: 10\nDisallow: /Admin/\nDisallow: /User/\n", 200, ['Content-Type' => 'text/plain']),
        TRACER_BAPTIST_ICS_URL => Http::response(
            sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'),
            200,
            ['Content-Type' => 'text/calendar; charset=utf-8', ...$headers],
        ),
    ]);
}

function tracerQueuedRun(Source $source): SourceRun
{
    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $source->id, 'status' => RunStatus::Queued])->save();

    return $run;
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->source = Source::factory()->ics()->active()->create([
        'name' => 'Attleborough Baptist Church',
        'url' => TRACER_BAPTIST_ICS_URL,
    ]);
});

it('turns a queued Baptist Church run into one pending draft per in-window item', function () {
    tracerFakeBaptist(['ETag' => '"abc"', 'Last-Modified' => 'Sat, 17 Oct 2026 04:00:00 GMT']);
    $run = tracerQueuedRun($this->source);

    FetchSourceJob::dispatchSync($run->id);

    $run->refresh();
    $drafts = EventDraft::query()->orderBy('id')->get();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->failure_kind)->toBeNull()
        ->and($run->items_found)->toBe(64)
        ->and($run->items_new)->toBeGreaterThan(0)
        ->and($run->drafts_created)->toBe($run->items_new)
        ->and($run->skipped_far_future)->toBeGreaterThan(0)
        ->and($run->items_found)->toBe($run->items_new + $run->items_changed + $run->items_unchanged
            + $run->skipped_past + $run->skipped_far_future + $run->skipped_rule + $run->skipped_parse_error)
        ->and($drafts)->toHaveCount($run->items_new)
        ->and(SourceItem::query()->count())->toBe($run->items_new)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->observed_from?->toDateString())->toBe('2026-10-17');

    foreach ($drafts as $draft) {
        $item = SourceItem::query()->findOrFail($draft->source_item_id);

        expect($draft->review_status)->toBe(ReviewStatus::PendingReview)
            ->and($draft->kind)->toBe(DraftKind::New)
            ->and($draft->origin)->toBe(EventOrigin::Discovered)
            ->and($draft->extraction_method)->toBe(ExtractionMethod::Ics)
            ->and($draft->source_id)->toBe($this->source->id)
            ->and($item->current_draft_id)->toBe($draft->id)
            ->and($item->status)->toBe(SourceItemStatus::Active)
            ->and($item->review_outcome)->toBe('pending')
            ->and($item->first_seen_run_id)->toBe($run->id)
            ->and($item->last_seen_run_id)->toBe($run->id);
    }
});

it('stores the raw body and updates the source after a successful run', function () {
    tracerFakeBaptist(['ETag' => '"abc"', 'Last-Modified' => 'Sat, 17 Oct 2026 04:00:00 GMT']);
    $run = tracerQueuedRun($this->source);

    FetchSourceJob::dispatchSync($run->id);

    $run->refresh();
    $source = $this->source->refresh();
    $body = sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics');

    expect($run->raw_path)->toBe(app(RawBodyStore::class)->pathFor($source->id, $run->id))
        ->and($run->raw_sha256)->toBe(hash('sha256', $body))
        ->and(app(RawBodyStore::class)->get($source->id, $run->id))->toBe($body)
        ->and($run->http_status)->toBe(200)
        ->and($source->etag)->toBe('"abc"')
        ->and($source->last_modified)->toBe('Sat, 17 Oct 2026 04:00:00 GMT')
        ->and($source->last_items_found)->toBe(64)
        ->and($source->last_run_at)->not->toBeNull()
        ->and($source->last_success_at)->not->toBeNull();

    $stored = DB::selectOne("select to_char(last_success_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as s from sources where id = ?", [$source->id]);

    // 06:00 BST = 05:00 UTC, plus the 10-second Crawl-delay wait.
    expect($stored->s)->toBe('2026-10-17 05:00');
});

it('creates no drafts when the same unchanged calendar is fetched again', function () {
    tracerFakeBaptist();
    $first = tracerQueuedRun($this->source);
    FetchSourceJob::dispatchSync($first->id);
    $first->refresh();
    $draftCount = EventDraft::query()->count();

    $this->travel(30)->minutes();
    $second = tracerQueuedRun($this->source->refresh());
    FetchSourceJob::dispatchSync($second->id);
    $second->refresh();

    expect($second->status)->toBe(RunStatus::Succeeded)
        ->and($second->drafts_created)->toBe(0)
        ->and($second->items_new)->toBe(0)
        ->and($second->items_changed)->toBe(0)
        ->and($second->items_unchanged)->toBe($first->items_new)
        ->and(EventDraft::query()->count())->toBe($draftCount)
        ->and(SourceItem::query()->where('last_seen_run_id', $second->id)->count())->toBe($first->items_new)
        ->and(SourceItem::query()->where('missing_runs', '>', 0)->count())->toBe(0);
});

it('ignores a job for a run that is no longer queued', function () {
    tracerFakeBaptist();
    $run = tracerQueuedRun($this->source);
    $run->forceFill(['status' => RunStatus::Failed, 'failure_kind' => 'abandoned'])->save();

    FetchSourceJob::dispatchSync($run->id);

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and(EventDraft::query()->count())->toBe(0);
    Http::assertNothingSent();
});
