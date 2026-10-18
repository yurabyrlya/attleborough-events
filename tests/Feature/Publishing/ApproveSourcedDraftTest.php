<?php

use App\Enums\ReviewStatus;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Jobs\FetchSourceJob;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Review\DraftPublishability;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * Tracer (03-08 Task 1): a discovered draft from the Baptist Church feed is
 * approved → the event is published and listed on /, it has exactly one
 * primary event_sources link, and the source item's accepted baseline is
 * the payload the admin approved (PIPE-06, LEG-04). All of it is one
 * transaction: a failing link leaves nothing behind.
 */

const APPROVE_SOURCED_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

/**
 * Runs the Baptist Church fixture through a queued live run (offline).
 */
function approveSourcedIngest(Source $source): SourceRun
{
    Http::fake([
        'https://www.attleborough.church/robots.txt' => Http::response("User-agent: *\nDisallow: /Admin/\n", 200, ['Content-Type' => 'text/plain']),
        APPROVE_SOURCED_ICS_URL => Http::response(
            sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'),
            200,
            ['Content-Type' => 'text/calendar; charset=utf-8'],
        ),
    ]);

    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $source->id, 'status' => RunStatus::Queued])->save();

    FetchSourceJob::dispatchSync($run->id);

    return $run->refresh();
}

/**
 * The earliest upcoming one-off draft from the run, given a venue so the
 * only thing between it and publication is the admin's approval.
 */
function approveSourcedPick(): EventDraft
{
    $venue = Venue::factory()->create(['name' => 'Attleborough Baptist Church']);

    $draft = EventDraft::query()
        ->whereNotNull('source_item_id')
        ->whereNull('recurrence_rule')
        ->where('start_date', '>=', '2026-10-17')
        ->orderBy('start_date')
        ->orderBy('start_time')
        ->orderBy('id')
        ->firstOrFail();

    // Test setup only: the admin would pick the venue on the edit page.
    $draft->forceFill(['venue_id' => $venue->id])->save();

    expect(app(DraftPublishability::class)->blockers($draft->refresh()))->toBe([]);

    return $draft;
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);

    $this->source = Source::factory()->ics()->active()->create([
        'name' => 'Attleborough Baptist Church',
        'url' => APPROVE_SOURCED_ICS_URL,
    ]);

    expect(approveSourcedIngest($this->source)->status)->toBe(RunStatus::Succeeded);
});

it('publishes a discovered draft, links it to its source as primary and sets the accepted baseline', function () {
    $draft = approveSourcedPick();
    $item = SourceItem::query()->findOrFail($draft->source_item_id);

    expect($item->accepted_hash)->toBeNull()
        ->and($item->review_outcome)->toBe('pending');

    $event = app(ApproveDraft::class)($draft, $this->admin);

    $draft->refresh();
    $item->refresh();
    $links = EventSource::query()->where('event_id', $event->id)->get();
    $itemUrl = $item->payload['url'] ?? null;

    expect($draft->review_status)->toBe(ReviewStatus::Approved)
        ->and($draft->event_id)->toBe($event->id)
        ->and($links)->toHaveCount(1)
        ->and($links[0]->source_id)->toBe($this->source->id)
        ->and($links[0]->source_item_id)->toBe($item->id)
        ->and($links[0]->is_primary)->toBeTrue()
        ->and($links[0]->linked_via)->toBe(EventSource::VIA_APPROVED)
        ->and($links[0]->item_url)->toBe(is_string($itemUrl) ? $itemUrl : null)
        ->and($links[0]->linked_by)->toBe($this->admin->id)
        ->and($links[0]->linked_at)->not->toBeNull()
        ->and($event->primarySource?->id)->toBe($links[0]->id)
        ->and($event->eventSources)->toHaveCount(1)
        ->and($item->accepted_hash)->toBe($item->content_hash)
        ->and($item->accepted_payload)->toBe($item->payload)
        ->and($item->review_outcome)->toBe('approved')
        ->and($item->current_draft_id)->toBe($draft->id);

    $linkedAt = DB::selectOne("select linked_at at time zone 'UTC' as t from event_sources where id = ?", [$links[0]->id]);

    // Frozen 06:00 BST plus the polite fetch waits: stored as UTC.
    expect(substr((string) $linkedAt->t, 0, 10))->toBe('2026-10-17');

    $this->get('/')->assertOk()->assertSee($event->title);
});

it('refuses a non-admin and writes no link, event or baseline', function () {
    $draft = approveSourcedPick();
    $stranger = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => app(ApproveDraft::class)($draft, $stranger))->toThrow(AuthorizationException::class);

    $item = SourceItem::query()->findOrFail($draft->source_item_id);

    expect(EventSource::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0)
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($item->accepted_hash)->toBeNull()
        ->and($item->review_outcome)->toBe('pending');
});

it('rolls the whole approval back when the source link cannot be written', function () {
    $draft = approveSourcedPick();
    $other = Event::factory()->create();

    // Forced conflict: the item is already linked to another event, so the
    // one-link-per-item constraint refuses the approval's link.
    DB::table('event_sources')->insert([
        'event_id' => $other->id,
        'source_id' => $this->source->id,
        'source_item_id' => $draft->source_item_id,
        'is_primary' => true,
        'linked_via' => 'approved',
        'linked_by' => $this->admin->id,
        'linked_at' => now()->utc()->format('Y-m-d H:i:s'),
    ]);

    expect(fn () => app(ApproveDraft::class)($draft, $this->admin))->toThrow(InvalidArgumentException::class);

    $item = SourceItem::query()->findOrFail($draft->source_item_id);

    expect(Event::query()->count())->toBe(1)
        ->and(DB::table('event_occurrences')->where('event_id', '!=', $other->id)->count())->toBe(0)
        ->and(EventSource::query()->count())->toBe(1)
        ->and($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->event_id)->toBeNull()
        ->and($item->accepted_hash)->toBeNull()
        ->and($item->review_outcome)->toBe('pending');
});

it('leaves manual drafts without any source link', function () {
    $draft = EventDraft::factory()->manual()->create();

    $event = app(ApproveDraft::class)($draft, $this->admin);

    expect($event->exists)->toBeTrue()
        ->and(EventSource::query()->count())->toBe(0);
});
