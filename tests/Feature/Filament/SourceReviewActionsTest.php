<?php

use App\Enums\DraftKind;
use App\Enums\OccurrenceStatus;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use App\Models\SourceItem;
use App\Publishing\Actions\ApplySourceUpdate;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * REV-06, REV-07, D-07, D-08, D-09 (03-13): the review page shows an update
 * draft's three-column diff with Apply changes / Ignore, a cancellation
 * draft's dates with Confirm cancellation / Ignore, and the Urgent badge.
 * Approve, Reject and the A key belong to new-event drafts only, so a
 * source change is never rejected without moving the accepted baseline.
 * Offline: ingestFeed(), the fake HostResolver and Sleep::fake.
 */

const SRA_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

const SRA_SERIES_URL = 'https://feed.example.org/series.ics';

/** TEC post 10003893: '[name removed] ~ Yoga', Sun 25 Oct 10:00-11:00 (GMT day). */
const SRA_YOGA_UID = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

function sraTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/** The Yoga session moved one hour later, as TEC exports it. */
function sraYogaMovedLater(): string
{
    return ingestEditEvent(sraTcFixture(), SRA_YOGA_UID, [
        SRA_YOGA_UID => 'UID:10003893-1792926000-1792929600@attleboroughtc.org.uk',
        'DTSTART;TZID=Europe/London:20261025T100000' => 'DTSTART;TZID=Europe/London:20261025T110000',
        'DTEND;TZID=Europe/London:20261025T110000' => 'DTEND;TZID=Europe/London:20261025T120000',
    ]);
}

/**
 * Publishes the 25 Oct Yoga session, then raises its update draft by
 * moving it an hour later at the source.
 *
 * @return array{0: Event, 1: EventDraft}
 */
function sraYogaUpdate(Source $tc): array
{
    ingestFeed(sraTcFixture());
    ingestLiveRun($tc);

    $event = ingestApprove(EventDraft::query()
        ->where('kind', DraftKind::New)
        ->where('title', '[name removed] ~ Yoga')
        ->where('start_date', '2026-10-25')
        ->sole());

    ingestFeed(sraYogaMovedLater());
    ingestLiveRun($tc->refresh());

    return [$event, EventDraft::query()->where('kind', DraftKind::Update)->where('review_status', ReviewStatus::PendingReview)->sole()];
}

/**
 * series.ics with a cancelled override of the Wednesday Folk Club (19:00)
 * on each of $dates (Ymd).
 *
 * @param  list<string>  $dates
 */
function sraFolkFeed(array $dates): string
{
    $blocks = '';

    foreach ($dates as $date) {
        $blocks .= implode("\r\n", [
            'BEGIN:VEVENT', 'UID:s-exdate@synthetic.invalid', 'DTSTAMP:20261017T050000Z',
            "RECURRENCE-ID;TZID=Europe/London:{$date}T190000", 'SUMMARY:Wednesday Folk Club',
            "DTSTART;TZID=Europe/London:{$date}T190000", 'STATUS:CANCELLED', 'END:VEVENT',
        ])."\r\n";
    }

    return str_replace('END:VCALENDAR', $blocks.'END:VCALENDAR', icsFixture('series.ics'));
}

/**
 * Publishes the Folk Club series, then raises a cancellation draft for 4 Nov.
 *
 * @return array{0: Event, 1: EventDraft}
 */
function sraFolkCancellation(Source $series): array
{
    ingestFeed(icsFixture('series.ics'));
    ingestLiveRun($series);

    $event = ingestApprove(EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole());

    ingestFeed(sraFolkFeed(['20261104']));
    ingestLiveRun($series->refresh());

    return [$event, EventDraft::query()->where('kind', DraftKind::Cancellation)->where('review_status', ReviewStatus::PendingReview)->sole()];
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => SRA_TC_URL,
    ]);
    $this->series = Source::factory()->ics()->active()->create(['name' => 'Folk Club calendar', 'url' => SRA_SERIES_URL]);

    actingAs(ingestAdmin());

    $this->page = fn (EventDraft $draft) => livewire(ReviewEventDraft::class, ['record' => $draft->id]);
});

it('shows the three-column diff and applies it: the event gets the new time', function () {
    [$event, $update] = sraYogaUpdate($this->tc);

    ($this->page)($update)
        ->assertOk()
        ->assertSee('Changes from Attleborough Town Council')
        ->assertSee(['Field', 'Published now', 'Source before', 'Source now'])
        ->assertSee(['Start time', 'End time', '10am', '11am', '12pm'])
        ->assertActionVisible('applyChanges')
        ->assertActionVisible('ignore')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('confirmCancellation')
        ->assertDontSee('Before you can publish')
        ->callAction('applyChanges')
        ->assertNotified('Changes applied');

    expect(substr((string) $event->refresh()->start_time, 0, 5))->toBe('11:00')
        ->and($update->refresh()->review_status)->toBe(ReviewStatus::Approved);

    // 25 Oct is the GMT day (BST ends): 11:00 London = 11:00 UTC.
    $start = DB::selectOne(
        "select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? and recurrence_key = '2026-10-25'",
        [$event->id],
    );
    expect((string) $start->s)->toBe('2026-10-25 11:00:00');
});

it('reports a stale Apply click as already reviewed and changes nothing more', function () {
    [, $update] = sraYogaUpdate($this->tc);

    $stale = ($this->page)($update);

    ($this->page)($update)->callAction('applyChanges')->assertNotified('Changes applied');

    $stale->callAction('applyChanges')->assertNotified(ReviewActions::ALREADY_REVIEWED);
});

it('refuses Apply with the plain message when the venue still needs picking', function () {
    [, $update] = sraYogaUpdate($this->tc);
    $update->forceFill([
        'venue_id' => null,
        'venue_name' => 'Nowhere Barn',
        'venue_postcode' => null,
        'diff' => [...$update->diff, 'venue' => ['published' => 'Town Hall', 'before' => 'Town Hall', 'now' => 'Nowhere Barn, 1 Lane']],
    ])->save();

    ($this->page)($update)
        ->callAction('applyChanges')
        ->assertNotified(Notification::make()->danger()->title("Can't apply this change")->body(ApplySourceUpdate::VENUE_NEEDED));

    expect($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('does not approve an update draft from the keyboard: A says to use Apply changes', function () {
    [, $update] = sraYogaUpdate($this->tc);

    ($this->page)($update)
        ->call('approveFromKeyboard')
        ->assertNotified('Use Apply changes for source updates.')
        ->assertActionNotMounted('approve')
        ->assertActionNotMounted('applyChanges');

    expect($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('ignores an update draft and moves the baseline, so the change is not raised again', function () {
    [$event, $update] = sraYogaUpdate($this->tc);

    ($this->page)($update)
        ->assertActionExists('ignore', fn (Action $action): bool => $action->getModalHeading() === 'Ignore this change from Attleborough Town Council?')
        ->callAction('ignore')
        ->assertNotified('Change ignored');

    $update->refresh();
    $item = SourceItem::query()->findOrFail($update->source_item_id);

    expect($update->review_status)->toBe(ReviewStatus::Rejected)
        ->and($update->reject_reason)->toBe(RejectReason::Ignored)
        ->and(substr((string) $event->refresh()->start_time, 0, 5))->toBe('10:00')
        ->and($item->accepted_hash)->toBe($item->content_hash);
});

it('maps the R key to Ignore on a source change draft and to Reject on a new draft', function () {
    [, $update] = sraYogaUpdate($this->tc);

    expect(($this->page)($update)->instance()->rejectActionName())->toBe('ignore');

    $new = EventDraft::query()->where('kind', DraftKind::New)->where('review_status', ReviewStatus::PendingReview)->firstOrFail();
    expect(($this->page)($new)->instance()->rejectActionName())->toBe('reject');
});

it('lists the cancelled date and confirms it: the public page shows Cancelled next to 4 Nov', function () {
    [$event, $cancellation] = sraFolkCancellation($this->series);

    ($this->page)($cancellation)
        ->assertSee('Cancelled at Folk Club calendar')
        ->assertSee('Wed 4 Nov')
        ->assertActionVisible('confirmCancellation')
        ->assertActionVisible('ignore')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('applyChanges')
        ->call('approveFromKeyboard')
        ->assertNotified('Use Confirm cancellation for source cancellations.')
        ->callAction('confirmCancellation')
        ->assertNotified('Cancellation confirmed');

    $occurrence = EventOccurrence::query()->where('event_id', $event->id)->where('recurrence_key', '2026-11-04')->sole();
    expect($occurrence->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($cancellation->refresh()->review_status)->toBe(ReviewStatus::Approved);

    $html = (string) $this->get(route('events.show', $event->slug))->assertOk()->getContent();
    expect(substr_count($html, '>Cancelled</strong>'))->toBe(1)
        ->and(preg_match('/Wed 4 Nov.{0,400}>Cancelled<\/strong>/s', $html))->toBe(1);
});

it('names the whole event for a whole-event cancellation', function () {
    [, $update] = sraYogaUpdate($this->tc);
    $update->forceFill([
        'kind' => DraftKind::Cancellation,
        'diff' => ['cancelled' => ['published' => false, 'before' => false, 'now' => true]],
    ])->save();

    ($this->page)($update)
        ->assertSee('Cancelled at Attleborough Town Council')
        ->assertSee('The whole event')
        ->assertActionVisible('confirmCancellation');
});

it('lists the dates a source marks cancelled on a new series draft', function () {
    $draft = EventDraft::factory()->create([
        'source_id' => $this->series->id,
        'raw_payload' => ['cancelled_keys' => ['2026-11-04', '2026-11-11']],
    ]);

    ($this->page)($draft)
        ->assertSee('Cancelled at source')
        ->assertSee(['Wed 4 Nov', 'Wed 11 Nov']);
});

it('shows the Urgent badge on an update due within 48 hours, and not on one further out', function () {
    [, $update] = sraYogaUpdate($this->tc);

    ($this->page)($update)->assertDontSee('Urgent');

    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00', 'Europe/London'));

    ($this->page)($update)->assertSee('Urgent');
});

it('offers Apply, Ignore and Confirm in the queue table instead of Reject for source changes', function () {
    [, $update] = sraYogaUpdate($this->tc);
    $new = EventDraft::query()->where('kind', DraftKind::New)->where('review_status', ReviewStatus::PendingReview)->firstOrFail();

    livewire(ListEventDrafts::class)
        ->assertActionHidden(TestAction::make('reject')->table($update))
        ->assertActionVisible(TestAction::make('applyChanges')->table($update))
        ->assertActionVisible(TestAction::make('ignore')->table($update))
        ->assertActionVisible(TestAction::make('reject')->table($new))
        ->assertActionHidden(TestAction::make('ignore')->table($new))
        ->callAction(TestAction::make('applyChanges')->table($update))
        ->assertNotified('Changes applied');

    expect($update->refresh()->review_status)->toBe(ReviewStatus::Approved);
});

it('skips source change drafts in bulk reject instead of rejecting them without moving the baseline', function () {
    [, $update] = sraYogaUpdate($this->tc);
    $new = EventDraft::query()->where('kind', DraftKind::New)->where('review_status', ReviewStatus::PendingReview)->firstOrFail();

    livewire(ListEventDrafts::class)
        ->callTableBulkAction('rejectSelected', [$update, $new], data: [])
        ->assertNotified('Rejected 1 of 2. The rest were skipped:');

    expect($update->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($new->refresh()->review_status)->toBe(ReviewStatus::Rejected);
});
