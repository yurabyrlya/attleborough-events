<?php

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApplySourceUpdate;
use App\Publishing\Actions\ConfirmSourceCancellation;
use App\Publishing\Actions\IgnoreSourceChange;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
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

/**
 * The admin's review save on the update draft with a venue picked
 * (DraftWriter::update, as the edit page does).
 */
function applyPickVenue(EventDraft $draft, Venue $venue): EventDraft
{
    return app(DraftWriter::class)->update($draft, new DraftData(
        origin: $draft->origin,
        extractionMethod: $draft->extraction_method,
        title: $draft->title,
        description: $draft->description,
        preferredCategoryId: $draft->category_id,
        venueId: $venue->id,
        venueName: $draft->venue_name,
        venueAddress: $draft->venue_address,
        venuePostcode: $draft->venue_postcode,
        allDay: $draft->all_day,
        startDate: $draft->start_date?->format('Y-m-d'),
        startTime: $draft->start_time,
        endDate: $draft->end_date?->format('Y-m-d'),
        endTime: $draft->end_time,
        recurrenceRule: $draft->recurrence_rule,
        repeatsText: $draft->repeats_text,
        repeatsAcknowledged: $draft->repeats_acknowledged,
        organiserContact: $draft->organiser_contact,
        sourceUrl: $draft->source_url,
    ));
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

it('refuses a second Apply of the same draft and leaves the event as the first Apply left it (REV-06 idempotency)', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());

    ($this->apply)($update, $this->admin);
    $after = applyEventSnapshot($event);

    expect(fn () => ($this->apply)($update, $this->admin))
        ->toThrow(InvalidReviewTransition::class, 'already reviewed');

    expect(applyEventSnapshot($event))->toEqual($after);
});

it('lets only the first of Apply and Ignore win on the same draft (REV-06 concurrency, draft row lock)', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());

    ($this->apply)($update, $this->admin);

    expect(fn () => app(IgnoreSourceChange::class)($update, $this->admin))
        ->toThrow(InvalidReviewTransition::class);
    expect($update->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($update->reject_reason)->toBeNull()
        ->and(substr((string) $event->refresh()->start_time, 0, 5))->toBe('11:00');

    // And the other way round: Ignore first, then Apply is refused.
    $this->travel(1)->hours();
    ingestFeed(applyTcFixture());
    ingestLiveRun($this->tc->refresh());
    $back = EventDraft::query()->where('kind', DraftKind::Update)->where('review_status', ReviewStatus::PendingReview)->sole();
    app(IgnoreSourceChange::class)($back, $this->admin);

    expect(fn () => ($this->apply)($back, $this->admin))->toThrow(InvalidReviewTransition::class)
        ->and(substr((string) $event->refresh()->start_time, 0, 5))->toBe('11:00');
});

it('refuses a venue change to an unknown venue until the admin picks the venue on the draft', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaEdited(['LOCATION:Town Hall' => 'LOCATION:Connaught Hall']));
    expect(array_keys($update->diff ?? []))->toBe(['venue'])
        ->and($update->venue_id)->toBeNull();

    $before = applyEventSnapshot($event);
    $baseline = applyYogaItem()->accepted_hash;

    expect(fn () => ($this->apply)($update, $this->admin))
        ->toThrow(DraftNotPublishable::class, ApplySourceUpdate::VENUE_NEEDED);
    expect(applyEventSnapshot($event))->toEqual($before)
        ->and($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(applyYogaItem()->accepted_hash)->toBe($baseline);

    $connaught = Venue::factory()->create(['name' => 'Connaught Hall (main hall)']);
    $picked = applyPickVenue($update, $connaught);
    expect($picked->diff)->toEqual($update->diff);

    $applied = ($this->apply)($picked, $this->admin);

    expect($applied->venue_id)->toBe($connaught->id)
        ->and(applyYogaItem()->accepted_hash)->toBe(applyYogaItem()->content_hash);
});

it('applies a venue change straight away when the new venue matches a known venue exactly', function () {
    ingestApprove(applyYogaNewDraft());
    $connaught = Venue::factory()->create(['name' => 'Connaught Hall']);
    $update = applyRaiseUpdate($this->tc, applyYogaEdited(['LOCATION:Town Hall' => 'LOCATION:Connaught Hall']));

    expect(($this->apply)($update, $this->admin)->venue_id)->toBe($connaught->id);
});

it('refuses an update that would turn a one-off into a series, and Ignore still works', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaEdited(['LOCATION:Town Hall' => "LOCATION:Town Hall\r\nRRULE:FREQ=WEEKLY;BYDAY=SU;COUNT=4"]));
    expect(array_keys($update->diff ?? []))->toContain('rrule');
    $before = applyEventSnapshot($event);

    expect(fn () => ($this->apply)($update, $this->admin))
        ->toThrow(DraftNotPublishable::class, ApplySourceUpdate::NOT_AUTOMATIC."An event can't switch between one-off and repeating.");
    expect(applyEventSnapshot($event))->toEqual($before)
        ->and($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview);

    $ignored = app(IgnoreSourceChange::class)($update, $this->admin);
    $item = applyYogaItem();

    expect($ignored->review_status)->toBe(ReviewStatus::Rejected)
        ->and($item->accepted_hash)->toBe($item->content_hash)
        ->and(applyEventSnapshot($event))->toEqual($before);
});

it('refuses an update draft carrying a parse error with that error, and Ignore moves the baseline', function () {
    $series = Source::factory()->ics()->active()->create(['name' => 'Synthetic series', 'url' => 'https://feed.example.org/series.ics']);
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);
    $event = ingestApprove(EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole());

    $override = implode("\r\n", [
        'BEGIN:VEVENT', 'UID:s-exdate@synthetic.invalid', 'DTSTAMP:20261017T050000Z',
        'RECURRENCE-ID;TZID=Europe/London:20261028T190000', 'SUMMARY:Wednesday Folk Club',
        'DTSTART;TZID=Europe/London:20261028T193000', 'DTEND;TZID=Europe/London:20261028T213000',
        'LOCATION:Town Hall\\, Queens Square\\, Attleborough\\, NR17 2AF', 'END:VEVENT',
    ])."\r\n";
    $update = applyRaiseUpdate($series, str_replace('END:VCALENDAR', $override.'END:VCALENDAR', icsFixture('series.ics')));
    $error = $update->parse_errors[0];
    $before = applyEventSnapshot($event);

    expect(fn () => ($this->apply)($update, $this->admin))
        ->toThrow(DraftNotPublishable::class, ApplySourceUpdate::NOT_AUTOMATIC.$error);
    expect(applyEventSnapshot($event))->toEqual($before);

    app(IgnoreSourceChange::class)($update, $this->admin);
    $item = SourceItem::query()->findOrFail($update->source_item_id);

    expect($item->accepted_hash)->toBe($item->content_hash)
        ->and(applyEventSnapshot($event))->toEqual($before);

    $this->travel(1)->hours();
    expect(ingestLiveRun($series->refresh())->drafts_created)->toBe(0);
});

it('skips diff keys with no published counterpart and still moves the baseline past them', function () {
    $event = ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());

    // An extra RDATE and a moved override shown beside the time change:
    // events cannot hold either (D-19), so only the time is written.
    $update->forceFill(['diff' => [
        ...$update->diff,
        'moved_overrides' => ['published' => [], 'before' => [], 'now' => [['key' => '2026-11-01', 'start' => '2026-11-01 12:00', 'end' => '2026-11-01 13:00']]],
        'extra_dates' => ['published' => [], 'before' => [], 'now' => ['2026-11-08']],
    ]])->save();

    $applied = ($this->apply)($update->refresh(), $this->admin);
    $item = applyYogaItem();

    expect(substr((string) $applied->start_time, 0, 5))->toBe('11:00')
        ->and($applied->recurrence_rule)->toBeNull()
        ->and(EventOccurrence::query()->where('event_id', $event->id)->pluck('recurrence_key')->all())->toBe(['2026-10-25'])
        ->and($item->accepted_hash)->toBe($item->content_hash);
});

it('refuses Apply on new and cancellation drafts and Confirm on an update draft, with a plain message', function () {
    ingestApprove(applyYogaNewDraft());
    $new = EventDraft::query()->where('kind', DraftKind::New)->where('review_status', ReviewStatus::PendingReview)->orderBy('id')->firstOrFail();

    expect(fn () => ($this->apply)($new, $this->admin))
        ->toThrow(DraftNotPublishable::class, ApplySourceUpdate::WRONG_KIND);

    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());

    expect(fn () => app(ConfirmSourceCancellation::class)($update, $this->admin))
        ->toThrow(DraftNotPublishable::class, ConfirmSourceCancellation::WRONG_KIND);

    ingestFeed(applyYogaEdited(["SUMMARY:[name removed] ~ Yoga\r\n" => "SUMMARY:[name removed] ~ Yoga\r\nSTATUS:CANCELLED\r\n"]));
    ingestLiveRun($this->tc->refresh());
    $cancellation = EventDraft::query()->where('kind', DraftKind::Cancellation)->where('review_status', ReviewStatus::PendingReview)->sole();

    expect(fn () => ($this->apply)($cancellation, $this->admin))
        ->toThrow(DraftNotPublishable::class, ApplySourceUpdate::WRONG_KIND)
        ->and($new->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($cancellation->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('refuses non-admins on all three decisions', function (string $action) {
    ingestApprove(applyYogaNewDraft());
    $update = applyRaiseUpdate($this->tc, applyYogaMovedLater());

    expect(fn () => app($action)($update, User::factory()->create()))
        ->toThrow(AuthorizationException::class);
    expect($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
})->with([ApplySourceUpdate::class, IgnoreSourceChange::class, ConfirmSourceCancellation::class]);

it('applies a changed repeat rule and excluded dates to a series', function () {
    $series = Source::factory()->ics()->active()->create(['name' => 'Synthetic series', 'url' => 'https://feed.example.org/series.ics']);
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);
    $event = ingestApprove(EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole());

    $changed = str_replace(
        "RRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=6\r\nEXDATE;TZID=Europe/London:20261021T190000\r\n",
        "RRULE:FREQ=WEEKLY;BYDAY=WE;COUNT=8\r\nEXDATE;TZID=Europe/London:20261021T190000,20261111T190000\r\n",
        icsFixture('series.ics'),
    );
    expect($changed)->not->toBe(icsFixture('series.ics'));

    $update = applyRaiseUpdate($series, $changed);
    expect(array_keys($update->diff ?? []))->toBe(['rrule', 'excluded_dates']);

    $applied = ($this->apply)($update, $this->admin);
    $keys = EventOccurrence::query()->where('event_id', $event->id)->orderBy('recurrence_key')->pluck('recurrence_key')->all();

    expect($applied->recurrence_rule)->toBe('FREQ=WEEKLY;BYDAY=WE;COUNT=8')
        ->and($applied->excluded_dates)->toBe(['2026-10-21', '2026-11-11'])
        ->and($keys)->toContain('2026-11-25', '2026-12-02')
        ->and($keys)->not->toContain('2026-11-11');
});
