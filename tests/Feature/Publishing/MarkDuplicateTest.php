<?php

use App\Enums\DraftKind;
use App\Enums\EventOrigin;
use App\Enums\ReviewStatus;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\DuplicateDetector;
use App\Models\Category;
use App\Models\DuplicateDecision;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\LinkEventSource;
use App\Publishing\Actions\MarkDraftDuplicate;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\RecordNotDuplicate;
use App\Publishing\Data\PublishableEventData;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * REV-05, D-10, D-11, D-13 (03-11): "Mark as duplicate" links the feed
 * item to the existing event (event_sources, linked_via duplicate), moves
 * the item's baseline and publishes nothing; later feed changes raise
 * update drafts for that event. "Not a duplicate" is remembered so the
 * same pair is never flagged again. Offline.
 */

const MARK_DUP_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const MARK_DUP_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function markDupTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/** @param  array<string, string>  $replacements */
function markDupYogaEdited(array $replacements): string
{
    return ingestEditEvent(markDupTcFixture(), MARK_DUP_YOGA_UID, $replacements);
}

/**
 * A hand-entered published event at the Town Hall, Sun 25 Oct 2026.
 */
function markDupPublish(string $title, string $time = '10:00'): Event
{
    $venue = Venue::query()->where('name', 'Town Hall')->first() ?? Venue::factory()->create(['name' => 'Town Hall']);

    return app(PublishEvent::class)(new PublishableEventData(
        title: $title,
        description: 'A gentle class for all levels.',
        categoryId: (int) Category::query()->orderBy('id')->value('id'),
        venueId: $venue->id,
        startDate: '2026-10-25',
        startTime: $time,
        organiserContact: 'yoga@example.test',
    ), ingestAdmin(), EventOrigin::Manual);
}

/** The 25 Oct Town Council Yoga draft (the new one). */
function markDupYogaDraft(): EventDraft
{
    return EventDraft::query()
        ->where('kind', DraftKind::New)
        ->where('title', '[name removed] ~ Yoga')
        ->where('start_date', '2026-10-25')
        ->sole();
}

/**
 * @return array<string, mixed>
 */
function markDupSnapshot(Event $event): array
{
    $event = Event::query()->findOrFail($event->id);

    return [
        'title' => $event->title,
        'description' => $event->description,
        'start_date' => $event->start_date->toDateString(),
        'start_time' => $event->start_time,
        'venue_id' => $event->venue_id,
        'status' => $event->status,
        'source_url' => $event->source_url,
        'updated_at' => $event->updated_at?->toIso8601String(),
        'occurrences' => $event->occurrences()->orderBy('id')->get(['id', 'starts_at', 'status'])->toArray(),
    ];
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->admin = ingestAdmin();
    $this->yoga = markDupPublish('Yoga with [name removed]');

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => MARK_DUP_TC_URL,
    ]);

    ingestFeed(markDupTcFixture());
    ingestLiveRun($this->tc);

    $this->draft = markDupYogaDraft();
    expect($this->draft->duplicate_of_event_id)->toBe($this->yoga->id);
});

it('marks the draft a duplicate, links the item to the event and publishes nothing (REV-05, D-10)', function () {
    $events = Event::query()->count();
    $before = markDupSnapshot($this->yoga);

    $marked = app(MarkDraftDuplicate::class)($this->draft, $this->yoga, $this->admin);

    expect($marked->review_status)->toBe(ReviewStatus::Duplicate)
        ->and($marked->duplicate_of_event_id)->toBe($this->yoga->id)
        ->and($marked->event_id)->toBeNull()
        ->and($marked->reviewed_by)->toBe($this->admin->id)
        ->and($marked->reviewed_at)->not->toBeNull()
        ->and(Event::query()->count())->toBe($events)
        ->and(markDupSnapshot($this->yoga))->toBe($before);

    $item = SourceItem::query()->findOrFail($this->draft->source_item_id);
    $link = EventSource::query()->where('source_item_id', $item->id)->sole();

    expect($link->event_id)->toBe($this->yoga->id)
        ->and($link->source_id)->toBe($this->tc->id)
        ->and($link->linked_via)->toBe(EventSource::VIA_DUPLICATE)
        ->and($link->is_primary)->toBeTrue()
        ->and($item->review_outcome)->toBe('duplicate')
        ->and($item->accepted_hash)->toBe($item->content_hash)
        ->and($item->accepted_payload)->toEqual($item->payload);

    $decision = DuplicateDecision::query()->sole();

    expect($decision->decision)->toBe(DuplicateDecision::DUPLICATE)
        ->and($decision->source_item_id)->toBe($item->id)
        ->and($decision->draft_id)->toBeNull()
        ->and($decision->candidate_event_id)->toBe($this->yoga->id)
        ->and($decision->candidate_draft_id)->toBeNull()
        ->and($decision->decided_by)->toBe($this->admin->id);
});

it('links as "also listed by" when the event already has a primary source (D-12)', function () {
    $other = Source::factory()->ics()->create(['name' => 'Yoga Studio']);
    app(LinkEventSource::class)($this->yoga, $other, null, EventSource::VIA_APPROVED, $this->admin);

    app(MarkDraftDuplicate::class)($this->draft, $this->yoga, $this->admin);

    $link = EventSource::query()->where('source_item_id', $this->draft->source_item_id)->sole();

    expect($link->is_primary)->toBeFalse()
        ->and(EventSource::query()->where('event_id', $this->yoga->id)->where('is_primary', true)->sole()->source_id)->toBe($other->id);
});

it('raises an update draft for the linked event when the feed later moves that session (D-11)', function () {
    app(MarkDraftDuplicate::class)($this->draft, $this->yoga, $this->admin);

    ingestFeed(markDupYogaEdited([
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]));
    ingestLiveRun($this->tc);

    $update = EventDraft::query()
        ->where('source_item_id', $this->draft->source_item_id)
        ->where('review_status', ReviewStatus::PendingReview)
        ->sole();

    expect($update->kind)->toBe(DraftKind::Update)
        ->and($update->event_id)->toBe($this->yoga->id)
        ->and(array_keys($update->diff ?? []))->toContain('start_time')
        ->and($update->diff['start_time']['now'])->toBe('11:00');

    // The published event is still as the admin entered it.
    expect(Event::query()->findOrFail($this->yoga->id)->start_time)->toStartWith('10:00');
});

it('remembers "Not a duplicate" so the same pair is never flagged again (D-13)', function () {
    $cleared = app(RecordNotDuplicate::class)($this->draft, $this->admin);

    expect($cleared->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($cleared->duplicate_of_event_id)->toBeNull()
        ->and($cleared->duplicate_of_draft_id)->toBeNull()
        ->and($cleared->duplicate_reason)->toBeNull()
        ->and($cleared->duplicate_score)->toBeNull();

    $decision = DuplicateDecision::query()->sole();

    expect($decision->decision)->toBe(DuplicateDecision::NOT_DUPLICATE)
        ->and($decision->source_item_id)->toBe($this->draft->source_item_id)
        ->and($decision->draft_id)->toBeNull()
        ->and($decision->candidate_event_id)->toBe($this->yoga->id);

    // A later run with a new description: the draft is not re-flagged.
    ingestFeed(markDupYogaEdited(["SUMMARY:[name removed] ~ Yoga\r\n" => "SUMMARY:[name removed] ~ Yoga\r\nDESCRIPTION:Bring a mat.\r\n"]));
    ingestLiveRun($this->tc);

    // A run that moves it 30 minutes (the New draft is replaced and
    // re-checked): still not flagged against that event.
    ingestFeed(markDupYogaEdited([
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T103000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T113000',
    ]));
    ingestLiveRun($this->tc);

    $pending = EventDraft::query()
        ->where('source_item_id', $this->draft->source_item_id)
        ->where('review_status', ReviewStatus::PendingReview)
        ->sole();

    expect($pending->start_time)->toStartWith('10:30')
        ->and($pending->duplicate_of_event_id)->toBeNull();

    // A fresh check of the item never returns that pair.
    $item = SourceItem::query()->findOrFail($this->draft->source_item_id);
    expect(app(DuplicateDetector::class)->find(NormalisedFacts::fromPayload($item->payload ?? []), $this->tc, $item, null))->toBeNull();
});

it('moves the warning to the next likely match after "Not a duplicate"', function () {
    $second = markDupPublish('[name removed] Yoga Session', '10:30');

    // Re-check from scratch: the two candidates now compete.
    markDupRecheck($this->draft);

    $draft = $this->draft->refresh();
    $first = $draft->duplicate_of_event_id;
    $other = $first === $this->yoga->id ? $second->id : $this->yoga->id;

    $cleared = app(RecordNotDuplicate::class)($draft, $this->admin);

    expect($cleared->duplicate_of_event_id)->toBe($other)
        ->and($cleared->duplicate_reason)->toBe('fuzzy')
        ->and(DuplicateDecision::query()->sole()->candidate_event_id)->toBe($first);
});

it('refuses to mark a draft as a duplicate of a pending draft', function () {
    $pending = EventDraft::factory()->manual()->create(['title' => 'Yoga at the Town Hall']);

    expect(fn () => app(MarkDraftDuplicate::class)($this->draft, $pending, $this->admin))
        ->toThrow(DraftNotPublishable::class, 'Approve or reject the other draft first.');

    expect($this->draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(EventSource::query()->count())->toBe(0)
        ->and(DuplicateDecision::query()->count())->toBe(0);
});

it('marks a submission draft as a duplicate of an event without a source link', function () {
    $submission = EventDraft::factory()->submission()->create(['title' => 'Yoga with [name removed]']);

    $marked = app(MarkDraftDuplicate::class)($submission, $this->yoga, $this->admin);

    expect($marked->review_status)->toBe(ReviewStatus::Duplicate)
        ->and($marked->duplicate_of_event_id)->toBe($this->yoga->id)
        ->and($marked->event_id)->toBeNull()
        ->and(EventSource::query()->count())->toBe(0);

    $decision = DuplicateDecision::query()->sole();

    expect($decision->draft_id)->toBe($submission->id)
        ->and($decision->source_item_id)->toBeNull()
        ->and($decision->candidate_event_id)->toBe($this->yoga->id);
});

it('refuses non-admins on both actions and changes nothing', function () {
    $intruder = User::factory()->create(['email' => 'someone@example.test']);

    expect(fn () => app(MarkDraftDuplicate::class)($this->draft, $this->yoga, $intruder))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordNotDuplicate::class)($this->draft, $intruder))->toThrow(AuthorizationException::class);

    $draft = $this->draft->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->duplicate_of_event_id)->toBe($this->yoga->id)
        ->and(EventSource::query()->count())->toBe(0)
        ->and(DuplicateDecision::query()->count())->toBe(0);
});

it('refuses an already-reviewed draft with InvalidReviewTransition', function () {
    app(MarkDraftDuplicate::class)($this->draft, $this->yoga, $this->admin);

    expect(fn () => app(MarkDraftDuplicate::class)($this->draft, $this->yoga, $this->admin))->toThrow(InvalidReviewTransition::class)
        ->and(fn () => app(RecordNotDuplicate::class)($this->draft, $this->admin))->toThrow(InvalidReviewTransition::class)
        ->and(EventSource::query()->count())->toBe(1)
        ->and(DuplicateDecision::query()->count())->toBe(1);
});

it('refuses update and cancellation drafts', function () {
    $update = EventDraft::factory()->create(['title' => 'Yoga with [name removed]']);
    $update->forceFill(['kind' => DraftKind::Update, 'event_id' => $this->yoga->id])->save();

    expect(fn () => app(MarkDraftDuplicate::class)($update, $this->yoga, $this->admin))
        ->toThrow(DraftNotPublishable::class, MarkDraftDuplicate::WRONG_KIND);
});

/**
 * Test-only: re-runs the duplicate check on a pending ingested draft, as the
 * next run's replace would.
 */
function markDupRecheck(EventDraft $draft): void
{
    $item = SourceItem::query()->findOrFail($draft->source_item_id);
    $source = Source::query()->findOrFail($draft->source_id);
    $match = app(DuplicateDetector::class)->find(NormalisedFacts::fromPayload($item->payload ?? []), $source, $item, $draft->venue_id);

    $draft->forceFill([
        'duplicate_of_event_id' => $match?->eventId,
        'duplicate_reason' => $match?->reason,
        'duplicate_score' => $match?->score,
    ])->save();
}
