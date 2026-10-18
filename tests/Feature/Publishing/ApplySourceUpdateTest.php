<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\Actions\ApplySourceUpdate;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * REV-06, D-07 (03-10): Apply takes every changed field of an update
 * draft's diff to the published event through UpdatePublishedEvent, keeps
 * every field the diff does not list, and moves the source item's accepted
 * baseline so the same change is not raised again. Offline: ingestFeed(),
 * the fake HostResolver and Sleep::fake.
 */

const APPLY_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

/** TEC post 10003893: '[name removed] ~ Yoga', Sun 25 Oct 10:00-11:00 (GMT day). */
const APPLY_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function applyTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/**
 * The Town Council fixture with the Yoga session's VEVENT edited.
 *
 * @param  array<string, string>  $replacements
 */
function applyYogaEdited(array $replacements): string
{
    return ingestEditEvent(applyTcFixture(), APPLY_YOGA_UID, $replacements);
}

/** The Yoga session moved one hour later, as TEC exports it. */
function applyYogaMovedLater(): string
{
    return applyYogaEdited([
        APPLY_YOGA_UID => 'UID:10003893-1792926000-1792929600@attleboroughtc.org.uk',
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]);
}

function applyYogaNewDraft(): EventDraft
{
    return EventDraft::query()
        ->where('kind', DraftKind::New)
        ->where('title', '[name removed] ~ Yoga')
        ->where('start_date', '2026-10-25')
        ->sole();
}

/**
 * Runs $feed through the Town Council source and returns the one pending
 * update draft it raised.
 */
function applyRaiseUpdate(Source $source, string $feed): EventDraft
{
    ingestFeed($feed);
    ingestLiveRun($source->refresh());

    return EventDraft::query()
        ->where('kind', DraftKind::Update)
        ->where('review_status', ReviewStatus::PendingReview)
        ->sole();
}

function applyYogaItem(): SourceItem
{
    return SourceItem::query()->where('external_id', '10003893')->sole();
}

/** A stable snapshot of the event row for "nothing changed" checks. */
function applyEventSnapshot(Event $event): array
{
    return Event::query()->findOrFail($event->id)->only([
        'title', 'slug', 'start_date', 'start_time', 'end_date', 'end_time', 'venue_id',
        'recurrence_rule', 'excluded_dates', 'status', 'updated_at',
    ]);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => APPLY_TC_URL,
    ]);

    ingestFeed(applyTcFixture());
    ingestLiveRun($this->tc);

    $this->admin = ingestAdmin();
    $this->apply = app(ApplySourceUpdate::class);
});

it('applies a moved Town Council session: new time on the public page, baseline moved, the next run raises nothing', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());

    $applied = ($this->apply)($update, $this->admin);

    expect($applied->id)->toBe($event->id)
        ->and(substr((string) $applied->start_time, 0, 5))->toBe('11:00')
        ->and(substr((string) $applied->end_time, 0, 5))->toBe('12:00');

    // 25 Oct is the GMT day (BST ends): 11:00 London = 11:00 UTC.
    $start = DB::selectOne(
        "select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? and recurrence_key = '2026-10-25'",
        [$event->id],
    );
    expect((string) $start->s)->toBe('2026-10-25 11:00:00');

    $update->refresh();
    $item = applyYogaItem();

    expect($update->review_status)->toBe(ReviewStatus::Approved)
        ->and($update->reviewed_by)->toBe($this->admin->id)
        ->and($update->reviewed_at)->not->toBeNull()
        ->and($update->event_id)->toBe($event->id)
        ->and($item->accepted_hash)->toBe($item->content_hash)
        ->and($item->accepted_payload)->toEqual($item->payload);

    $this->get('/')->assertOk()->assertSee('11am')->assertDontSee('10am');
    $this->get(route('events.show', $applied->slug))->assertOk()->assertSee('11am')->assertDontSee('10am');

    $this->travel(1)->hours();
    $again = ingestLiveRun($this->tc->refresh());

    expect($again->drafts_created)->toBe(0)
        ->and($again->drafts_replaced)->toBe(0)
        ->and(EventDraft::query()->where('review_status', ReviewStatus::PendingReview)->where('kind', '<>', DraftKind::New)->count())->toBe(0);
});

it("keeps the admin's own edits to fields the diff does not list (D-07)", function () {
    $event = ingestApprove(applyYogaNewDraft());

    // The admin retitled and re-described the event before the feed moved it.
    $form = PublishableEventData::fromEvent($event);
    $form['title'] = 'Town Hall Yoga';
    $form['description'] = 'Gentle yoga for all abilities.';
    app(UpdatePublishedEvent::class)($event, PublishableEventData::fromForm($form), $this->admin);

    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());
    expect(array_keys($update->diff ?? []))->toBe(['start_time', 'end_time']);

    $applied = ($this->apply)($update, $this->admin);

    expect($applied->title)->toBe('Town Hall Yoga')
        ->and($applied->description)->toBe('Gentle yoga for all abilities.')
        ->and($applied->venue_id)->toBe($event->venue_id)
        ->and($applied->category_id)->toBe($event->category_id)
        ->and(substr((string) $applied->start_time, 0, 5))->toBe('11:00');
});

it('refuses a non-admin and changes nothing', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());
    $before = applyEventSnapshot($event);
    $baseline = applyYogaItem()->accepted_hash;

    expect(fn () => ($this->apply)($update, User::factory()->create()))
        ->toThrow(AuthorizationException::class);

    expect(applyEventSnapshot($event))->toEqual($before)
        ->and($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(applyYogaItem()->accepted_hash)->toBe($baseline);
});
