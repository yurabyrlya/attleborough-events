<?php

use App\Enums\DraftKind;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\LinkEventSource;
use App\Publishing\Actions\MarkDraftDuplicate;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\DraftNotReopenable;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * Review CR-03: a feed item can have an old rejected draft A and a later
 * approved draft B that linked it to an event (D-23 re-draft). Reopening A
 * must not turn the linked item back into a "pending new" one; Approve and
 * Mark as duplicate on such a draft are plain refusals, never a 500; and
 * rejecting it again never moves the linked item's baseline. Offline:
 * Http::fake through ingestFeed(), Sleep::fake.
 */

const LINKED_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

/** TEC post 10003893: '[name removed] ~ Yoga', Sun 25 Oct 10:00-11:00. */
const LINKED_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

/** The Town Council fixture with the Yoga session starting at $hour. */
function linkedYogaAt(int $hour): string
{
    $start = sprintf('%02d', $hour);
    $end = sprintf('%02d', $hour + 1);

    return ingestEditEvent(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'), LINKED_YOGA_UID, [
        'DTSTART;TZID=Europe/London:20261025T100000' => "DTSTART;TZID=Europe/London:20261025T{$start}0000",
        'DTEND;TZID=Europe/London:20261025T110000' => "DTEND;TZID=Europe/London:20261025T{$end}0000",
    ]);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->admin = ingestAdmin();
    actingAs($this->admin);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => LINKED_TC_URL,
        'default_venue_id' => Venue::factory()->create(['name' => 'Town Hall'])->id,
    ]);

    ingestFeed(linkedYogaAt(10));
    ingestLiveRun($this->tc);

    // Draft A (10:00) is rejected; the item's baseline moves to 10:00.
    $this->a = EventDraft::query()->where('kind', DraftKind::New)->where('title', '[name removed] ~ Yoga')->where('start_date', '2026-10-25')->sole();
    app(RejectDraft::class)($this->a, $this->admin, RejectReason::Other);

    // The feed moves it to 11:00: D-23 re-drafts it as B, which is approved.
    ingestFeed(linkedYogaAt(11));
    $this->travel(1)->hours();
    ingestLiveRun($this->tc->refresh());

    $this->b = EventDraft::query()->where('source_item_id', $this->a->source_item_id)->where('review_status', ReviewStatus::PendingReview)->sole();
    $this->event = app(ApproveDraft::class)($this->b, $this->admin);

    $this->item = SourceItem::query()->findOrFail($this->a->source_item_id);
    $this->baseline = $this->item->accepted_hash;

    expect($this->item->review_outcome)->toBe('approved')
        ->and(EventSource::linksItem($this->item->id))->toBeTrue();
});

/**
 * The state a pre-fix Reopen left behind: draft A pending again and its
 * linked item pending with A as its current draft.
 */
function linkedLegacyReopen(EventDraft $a, SourceItem $item): EventDraft
{
    $a->refresh()->forceFill(['review_status' => ReviewStatus::PendingReview, 'reject_reason' => null, 'reviewed_by' => null, 'reviewed_at' => null])->save();
    $item->refresh()->forceFill(['review_outcome' => 'pending', 'current_draft_id' => $a->id])->save();

    return $a->refresh();
}

it('refuses to reopen an old rejected draft whose feed item has since been published', function () {
    expect(fn () => app(ReopenDraft::class)($this->a->refresh(), $this->admin))
        ->toThrow(DraftNotReopenable::class, ReopenDraft::ITEM_ALREADY_PUBLISHED);

    $item = $this->item->refresh();

    expect($this->a->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($item->review_outcome)->toBe('approved')
        ->and($item->current_draft_id)->toBe($this->b->id)
        ->and($item->accepted_hash)->toBe($this->baseline);
});

it('hides Reopen on the review page of that draft', function () {
    livewire(ReviewEventDraft::class, ['record' => $this->a->id])
        ->assertActionHidden('reopen');
});

it('still offers Reopen for a rejected draft whose item was never published', function () {
    $other = EventDraft::query()->whereNotNull('source_item_id')->where('review_status', ReviewStatus::PendingReview)->where('kind', DraftKind::New)->firstOrFail();
    app(RejectDraft::class)($other, $this->admin);

    livewire(ReviewEventDraft::class, ['record' => $other->id])
        ->assertActionVisible('reopen')
        ->callAction('reopen')
        ->assertNotified('Draft reopened');

    expect($other->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('refuses Approve on a pending draft whose item is already linked, with a message and nothing published', function () {
    $a = linkedLegacyReopen($this->a, $this->item);
    $events = Event::count();

    expect(fn () => app(ApproveDraft::class)($a, $this->admin))
        ->toThrow(DraftNotPublishable::class, LinkEventSource::ITEM_ALREADY_PUBLISHED);

    livewire(ReviewEventDraft::class, ['record' => $a->id])
        ->callAction('approve')
        ->assertNotified("Can't publish yet");

    expect(Event::count())->toBe($events)
        ->and($a->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('refuses Mark as duplicate on a pending draft whose item is already linked, with a message', function () {
    $a = linkedLegacyReopen($this->a, $this->item);
    $a->forceFill(['duplicate_of_event_id' => $this->event->id])->save();

    expect(fn () => app(MarkDraftDuplicate::class)($a->refresh(), $this->event, $this->admin))
        ->toThrow(DraftNotPublishable::class, LinkEventSource::ITEM_ALREADY_PUBLISHED);

    livewire(ReviewEventDraft::class, ['record' => $a->id])
        ->callAction('markDuplicate')
        ->assertNotified("Can't mark as duplicate");

    expect($a->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(EventSource::query()->where('source_item_id', $this->item->id)->count())->toBe(1);
});

it('never moves a linked item\'s baseline when that draft is rejected again', function () {
    $a = linkedLegacyReopen($this->a, $this->item);

    // The pending draft absorbed a newer feed state (12:00) on the item.
    $this->item->forceFill(['content_hash' => str_repeat('f', 64), 'payload' => [...($this->item->payload ?? []), 'start_time' => '12:00']])->save();

    app(RejectDraft::class)($a, $this->admin);

    $item = $this->item->refresh();

    expect($a->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($item->accepted_hash)->toBe($this->baseline)
        ->and($item->accepted_payload['start_time'] ?? null)->not->toBe('12:00')
        ->and($item->review_outcome)->toBe('approved');
});

it('raises an update for the published event, not a replace of the stale new draft, when the feed changes', function () {
    $a = linkedLegacyReopen($this->a, $this->item);

    ingestFeed(linkedYogaAt(12));
    $this->travel(1)->hours();
    ingestLiveRun($this->tc->refresh());

    $update = EventDraft::query()->where('kind', DraftKind::Update)->sole();

    expect($update->event_id)->toBe($this->event->id)
        ->and($update->diff['start_time'] ?? null)->toEqual(['published' => '11:00', 'before' => '11:00', 'now' => '12:00'])
        ->and($a->refresh()->review_status)->toBe(ReviewStatus::Superseded)
        ->and($a->start_time)->toBe('10:00:00');
});
