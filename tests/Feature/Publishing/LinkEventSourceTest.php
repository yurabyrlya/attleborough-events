<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\Actions\LinkEventSource;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Actions\ReopenDraft;
use App\Review\Exceptions\DraftNotReopenable;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
 * Primary attribution (D-12, D-25), one link per item, and source item
 * bookkeeping on reject/reopen (D-23, C14).
 */

/**
 * A source item as the recorder leaves it: active, review pending.
 */
function linkTestItem(Source $source, ?string $url = null): SourceItem
{
    $item = new SourceItem([
        'external_id' => 'uid-'.Str::lower(Str::random(10)).'@example.org',
        'content_hash' => hash('sha256', Str::random(16)),
        'payload' => ['title' => 'Quiz night', 'url' => $url],
    ]);
    $item->forceFill(['source_id' => $source->id, 'review_outcome' => 'pending'])->save();

    return $item;
}

/**
 * A pending discovered draft for $item, recorded as the item's current draft.
 */
function linkTestDraft(Source $source, SourceItem $item, DraftKind $kind = DraftKind::New, array $state = []): EventDraft
{
    $draft = EventDraft::factory()->create([
        'origin' => EventOrigin::Discovered,
        'extraction_method' => ExtractionMethod::Ics,
        'kind' => $kind,
        'source_id' => $source->id,
        'source_item_id' => $item->id,
        ...$state,
    ]);

    $item->forceFill(['current_draft_id' => $draft->id])->save();

    return $draft;
}

/**
 * The ids of the event's primary links (there must only ever be one).
 *
 * @return list<int>
 */
function linkTestPrimaryIds(Event $event): array
{
    return EventSource::query()->where('event_id', $event->id)->where('is_primary', true)->pluck('id')->all();
}

beforeEach(function () {
    freezeLondon('2026-10-17 10:00');
    $this->seed(CategorySeeder::class);
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    $this->event = Event::factory()->create();
    $this->link = app(LinkEventSource::class);
});

it('makes the first link primary, lets an organiser source take over and keeps later links as also listed by', function () {
    $town = Source::factory()->ics()->create(['name' => 'Town Council']);
    $heritage = Source::factory()->ics()->organiserSite()->create(['name' => 'Heritage Group']);
    $church = Source::factory()->ics()->create(['name' => 'Baptist Church']);

    $a = ($this->link)($this->event, $town, linkTestItem($town, 'https://town.example.org/e/1'), EventSource::VIA_APPROVED, $this->admin);

    expect($a->is_primary)->toBeTrue()
        ->and($a->item_url)->toBe('https://town.example.org/e/1')
        ->and(linkTestPrimaryIds($this->event))->toBe([$a->id]);

    $b = ($this->link)($this->event, $heritage, linkTestItem($heritage), EventSource::VIA_DUPLICATE, $this->admin);

    expect($b->is_primary)->toBeTrue()
        ->and($b->item_url)->toBeNull()
        ->and($a->refresh()->is_primary)->toBeFalse()
        ->and(linkTestPrimaryIds($this->event))->toBe([$b->id]);

    $c = ($this->link)($this->event, $church, linkTestItem($church), EventSource::VIA_DUPLICATE, $this->admin);

    expect($c->is_primary)->toBeFalse()
        ->and(linkTestPrimaryIds($this->event))->toBe([$b->id])
        ->and($this->event->primarySource?->source_id)->toBe($heritage->id)
        ->and($this->event->eventSources->pluck('source_id')->all())->toBe([$town->id, $heritage->id, $church->id]);
});

it('keeps the first approved organiser source as primary when a second organiser source links', function () {
    $first = Source::factory()->ics()->organiserSite()->create();
    $second = Source::factory()->ics()->organiserSite()->create();

    $a = ($this->link)($this->event, $first, linkTestItem($first), EventSource::VIA_APPROVED, $this->admin);
    $b = ($this->link)($this->event, $second, linkTestItem($second), EventSource::VIA_DUPLICATE, $this->admin);

    expect($a->refresh()->is_primary)->toBeTrue()
        ->and($b->is_primary)->toBeFalse()
        ->and(linkTestPrimaryIds($this->event))->toBe([$a->id]);
});

it('refuses to link the same feed item twice and changes nothing', function () {
    $source = Source::factory()->ics()->create();
    $item = linkTestItem($source);
    $other = Event::factory()->create();

    $first = ($this->link)($this->event, $source, $item, EventSource::VIA_APPROVED, $this->admin);

    expect(fn () => ($this->link)($other, $source, $item, EventSource::VIA_DUPLICATE, $this->admin))
        ->toThrow(InvalidArgumentException::class, LinkEventSource::ITEM_ALREADY_LINKED);

    expect(EventSource::query()->count())->toBe(1)
        ->and(linkTestPrimaryIds($other))->toBe([])
        ->and($first->refresh()->event_id)->toBe($this->event->id);
});

it('refuses a non-admin, an unknown link type and an item from another source', function () {
    $source = Source::factory()->ics()->create();
    $otherSource = Source::factory()->ics()->create();
    $stranger = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => ($this->link)($this->event, $source, linkTestItem($source), EventSource::VIA_APPROVED, $stranger))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->link)($this->event, $source, linkTestItem($source), 'scraped', $this->admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->link)($this->event, $source, linkTestItem($otherSource), EventSource::VIA_APPROVED, $this->admin))
        ->toThrow(InvalidArgumentException::class);

    expect(EventSource::query()->count())->toBe(0);
});

it('keeps a rejected feed item rejected with its baseline moved, and reopening makes it pending again', function () {
    $source = Source::factory()->ics()->create();
    $item = linkTestItem($source);
    $draft = linkTestDraft($source, $item);

    app(RejectDraft::class)($draft, $this->admin, RejectReason::NotAnEvent);
    $item->refresh();

    expect($item->review_outcome)->toBe('rejected')
        ->and($item->accepted_hash)->toBe($item->content_hash)
        ->and($item->accepted_payload)->toBe($item->payload)
        ->and($item->current_draft_id)->toBe($draft->id);

    $reopened = app(ReopenDraft::class)($draft, $this->admin);
    $item->refresh();

    expect($reopened->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($item->review_outcome)->toBe('pending')
        ->and($item->current_draft_id)->toBe($draft->id);
});

it('refuses to reopen update and cancellation drafts', function (DraftKind $kind) {
    $source = Source::factory()->ics()->create();
    $item = linkTestItem($source);
    $draft = linkTestDraft($source, $item, $kind, [
        'event_id' => $this->event->id,
        'review_status' => ReviewStatus::Rejected,
        'reject_reason' => RejectReason::Ignored,
        'reviewed_at' => now()->utc(),
    ]);

    expect(fn () => app(ReopenDraft::class)($draft, $this->admin))
        ->toThrow(DraftNotReopenable::class, 'Only new-event drafts can be reopened.');

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($item->refresh()->review_outcome)->toBe('pending');
})->with([DraftKind::Update, DraftKind::Cancellation]);

it('refuses to reopen a rejected draft while its feed item has a newer pending draft', function () {
    $source = Source::factory()->ics()->create();
    $item = linkTestItem($source);
    $old = linkTestDraft($source, $item);
    app(RejectDraft::class)($old, $this->admin);

    $newer = linkTestDraft($source, $item);

    expect(fn () => app(ReopenDraft::class)($old, $this->admin))
        ->toThrow(DraftNotReopenable::class, ReopenDraft::NEWER_DRAFT_PENDING);

    expect($old->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($item->refresh()->current_draft_id)->toBe($newer->id);
});
