<?php

use App\Enums\DraftKind;
use App\Enums\EventStatus;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\Venue;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F16 (update draft: three-column diff, Apply changes), F17
 * (cancellation draft: Confirm cancellation, Ignore), F18 (duplicate
 * warning: side-by-side panel, Not a duplicate, Mark as duplicate) and F19
 * (Approve all like this, Urgent first), docs/uat/browser-uat.md.
 *
 * State is built only through ingestion: the seeded Town Council source is
 * taken live (test run, Create drafts) on its captured feed, earlier drafts
 * are approved through ApproveDraft, and changes come from a second check
 * of an edited copy of the feed. Offline (Uat::fakeIngestion()); frozen at
 * Sat 17 Oct 2026 06:00 London.
 */
beforeEach(function () {
    freezeLondon('2026-10-17 06:00');
    Uat::seedCategories();
    Uat::fakeIngestion();

    $this->townHall = Venue::factory()->create(['name' => 'Town Hall']);
    $this->admin = Uat::admin();
    actingAs($this->admin);
});

const SREV_YOGA = '[name removed] ~ Yoga';

/** TEC posts of the three Yoga sessions in the Town Council fixture. */
const SREV_YOGA_18_OCT = 'UID:10003892-1792317600-1792321200@attleboroughtc.org.uk';

const SREV_YOGA_21_OCT = 'UID:10006475-1792605600-1792612800@attleboroughtc.org.uk';

const SREV_YOGA_25_OCT = 'UID:10003893-1792922400-1792926000@attleboroughtc.org.uk';

/**
 * The seeded Town Council source, live, with the Town Hall as its default
 * venue and Community as its default category (what the admin would pick
 * on the source form before Create drafts).
 */
function srevTownCouncil(Venue $townHall): Source
{
    return Uat::ingestedSource(SourceSeeder::TOWN_COUNCIL, [
        'default_venue_id' => $townHall->id,
        'default_category_id' => Uat::category('community')->id,
    ]);
}

function srevYogaDraft(string $date, DraftKind $kind = DraftKind::New): EventDraft
{
    return EventDraft::query()
        ->where('kind', $kind)
        ->where('title', SREV_YOGA)
        ->where('start_date', $date)
        ->where('review_status', ReviewStatus::PendingReview)
        ->sole();
}

/**
 * $ics with the Yoga session $uid moved one hour later, as TEC exports a
 * moved post (the UID keeps the post id; its timestamps change).
 */
function srevMoveLater(string $ics, string $uid, string $date, string $start, string $end, string $later, string $laterEnd): string
{
    return ingestEditEvent($ics, $uid, [
        "DTSTART;TZID=Europe/London:{$date}T{$start}" => "DTSTART;TZID=Europe/London:{$date}T{$later}",
        "DTEND;TZID=Europe/London:{$date}T{$end}" => "DTEND;TZID=Europe/London:{$date}T{$laterEnd}",
    ]);
}

function srevCancel(string $ics, string $uid): string
{
    return ingestEditEvent($ics, $uid, ['SUMMARY:'.SREV_YOGA."\r\n" => 'SUMMARY:'.SREV_YOGA."\r\nSTATUS:CANCELLED\r\n"]);
}

/**
 * Open Review queue → Pending and search it for the Yoga sessions (the
 * queue shows 10 rows a page), waiting until $rows rows are listed.
 */
function srevQueueForYoga(mixed $page, int $rows): mixed
{
    $page->navigate('/admin/event-drafts')
        ->waitForText('Book Sale')
        ->fill('.fi-ta-search-field input', 'Yoga');

    return Uat::waitUntil($page, "document.querySelectorAll('tr.fi-ta-row').length === {$rows}", 10.0);
}

/**
 * Click a review action, then its dialog's submit button.
 */
function srevConfirm(mixed $page, string $label, string $heading, string $submit): mixed
{
    Uat::dismissNotifications($page);

    $page->click(Uat::role('button', $label))
        ->waitForText($heading);

    return $page->click(Uat::OPEN_MODAL.' >> '.Uat::role('button', $submit));
}

it('F16: an update draft shows the three-column diff and Apply changes moves the published time', function () {
    $tc = srevTownCouncil($this->townHall);
    $event = ingestApprove(srevYogaDraft('2026-10-25'));

    Uat::serveFeed(Uat::TOWN_COUNCIL_FEED, srevMoveLater(Uat::feedFixture(Uat::TOWN_COUNCIL_FEED), SREV_YOGA_25_OCT, '20261025', '100000', '110000', '110000', '120000'));
    Uat::runSource($tc);

    $update = srevYogaDraft('2026-10-25', DraftKind::Update);

    // From the queue's Pending tab: new drafts for 18 and 21 Oct, the update for 25 Oct.
    $page = srevQueueForYoga(visit('/admin'), 3)
        ->click('tr.fi-ta-row:has-text("Update") >> text="'.SREV_YOGA.'"')
        ->waitForText('Changes from Attleborough Town Council')
        ->assertPathIs(Uat::reviewPath($update));

    $diff = $page->script(<<<'JS'
        () => [...document.querySelectorAll('table:has(caption) tr')]
            .map((tr) => [...tr.cells].map((cell) => cell.innerText.trim()))
    JS);

    expect($diff)->toContain(['Field', 'Published now', 'Source before', 'Source now'])
        ->toContain(['Start time', '10am', '10am', '11am'])
        ->toContain(['End time', '11am', '11am', '12pm']);

    $page->assertPresent(Uat::role('button', 'Ignore (R)'))
        ->assertNotPresent(Uat::role('button', 'Approve & next (A)'))
        ->assertNotPresent(Uat::role('button', 'Reject (R)'))
        ->assertDontSee('Before you can publish');

    srevConfirm($page, 'Apply changes', 'Apply these changes to the published event?', 'Apply changes')
        ->waitForText('Changes applied')
        ->assertNoJavaScriptErrors();

    expect($update->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and(substr((string) $event->refresh()->start_time, 0, 5))->toBe('11:00');

    // Sun 25 Oct is the day the clocks go back: 11:00 London is 11:00 UTC.
    $start = DB::selectOne(
        "select starts_at at time zone 'UTC' as s from event_occurrences where event_id = ? and recurrence_key = '2026-10-25'",
        [$event->id],
    );
    expect((string) $start->s)->toBe('2026-10-25 11:00:00');

    $page->navigate('/events/'.$event->slug)
        ->waitForText(SREV_YOGA)
        ->assertSee('Sun 25 Oct, 11am')
        ->assertDontSee('Sun 25 Oct, 10am')
        ->assertNoJavaScriptErrors();
});

it('F17: Confirm cancellation shows "Cancelled" on the event page; Ignore takes the other off Pending', function () {
    $tc = srevTownCouncil($this->townHall);
    $wednesday = ingestApprove(srevYogaDraft('2026-10-21'));
    $sunday = ingestApprove(srevYogaDraft('2026-10-25'));

    $feed = srevCancel(srevCancel(Uat::feedFixture(Uat::TOWN_COUNCIL_FEED), SREV_YOGA_21_OCT), SREV_YOGA_25_OCT);
    Uat::serveFeed(Uat::TOWN_COUNCIL_FEED, $feed);
    Uat::runSource($tc);

    $cancelWednesday = srevYogaDraft('2026-10-21', DraftKind::Cancellation);
    $cancelSunday = srevYogaDraft('2026-10-25', DraftKind::Cancellation);

    // Soonest first: after the new 18 Oct draft, Wed 21 Oct is the first Cancellation row.
    $page = srevQueueForYoga(visit('/admin'), 3)
        ->click('tr.fi-ta-row:has-text("Cancellation") >> nth=0 >> text="'.SREV_YOGA.'"')
        ->waitForText('Cancelled at Attleborough Town Council')
        ->assertPathIs(Uat::reviewPath($cancelWednesday))
        ->assertSee('The whole event')
        ->assertNotPresent(Uat::role('button', 'Approve & next (A)'));

    srevConfirm($page, 'Confirm cancellation', 'Confirm this cancellation?', 'Confirm cancellation')
        ->waitForText('Cancellation confirmed');

    expect($cancelWednesday->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($wednesday->refresh()->status)->toBe(EventStatus::Cancelled);

    srevQueueForYoga($page, 2)
        ->click('tr.fi-ta-row:has-text("Cancellation") >> text="'.SREV_YOGA.'"')
        ->waitForText('Cancelled at Attleborough Town Council')
        ->assertPathIs(Uat::reviewPath($cancelSunday));

    srevConfirm($page, 'Ignore (R)', 'Ignore this change from Attleborough Town Council?', 'Ignore change')
        ->waitForText('Change ignored');

    expect($cancelSunday->refresh()->review_status)->toBe(ReviewStatus::Rejected)
        ->and($cancelSunday->reject_reason)->toBe(RejectReason::Ignored);

    srevQueueForYoga($page, 1)
        ->assertNotPresent('tr.fi-ta-row:has-text("Cancellation")');

    $page->navigate('/events/'.$wednesday->slug)
        ->waitForText(SREV_YOGA)
        ->assertSeeIn('main >> h1', 'Cancelled')
        ->assertSee('This event has been cancelled.');

    $page->navigate('/events/'.$sunday->slug)
        ->waitForText(SREV_YOGA)
        ->assertDontSee('Cancelled')
        ->assertNoJavaScriptErrors();
});

it('F18: the duplicate panel compares side by side; Not a duplicate clears it, Mark as duplicate publishes nothing', function () {
    // Hand-entered Yoga classes at the Town Hall, published before the feed is read.
    foreach (['2026-10-18', '2026-10-25'] as $date) {
        Uat::publish($this->admin, [
            'title' => 'Yoga with [name removed]',
            'description' => 'A gentle class for all levels.',
            'venueId' => $this->townHall->id,
            'startDate' => $date,
            'startTime' => '10:00:00',
            'organiserContact' => 'yoga@example.test',
        ]);
    }

    srevTownCouncil($this->townHall);

    $sunday = srevYogaDraft('2026-10-25');
    $earlier = srevYogaDraft('2026-10-18');
    expect($sunday->duplicate_of_event_id)->not->toBeNull()
        ->and($earlier->duplicate_of_event_id)->not->toBeNull();

    $events = Event::query()->count();

    $page = visit(Uat::reviewPath($sunday))
        ->waitForText('Possible duplicate')
        ->assertSee('This draft')
        ->assertSee('Published event')
        ->assertSee('Yoga with [name removed]')
        ->assertSee('Same date, similar venue and title (similarity 0.78)')
        ->assertPresent(Uat::role('button', 'Mark as duplicate'));

    srevConfirm($page, 'Not a duplicate', 'Not a duplicate?', 'Not a duplicate')
        ->waitForText('Noted: not a duplicate');
    Uat::waitUntil($page, "! document.body.innerText.includes('Same date, similar venue and title')");
    $page->assertPathIs(Uat::reviewPath($sunday))
        ->assertNotPresent(Uat::role('button', 'Mark as duplicate'));

    expect($sunday->refresh()->duplicate_of_event_id)->toBeNull()
        ->and($sunday->review_status)->toBe(ReviewStatus::PendingReview);

    $page->navigate(Uat::reviewPath($earlier))
        ->waitForText('Possible duplicate');

    srevConfirm($page, 'Mark as duplicate', 'Mark as duplicate?', 'Mark as duplicate')
        ->waitForText('Marked as duplicate');

    expect($earlier->refresh()->review_status)->toBe(ReviewStatus::Duplicate)
        ->and(Event::query()->count())->toBe($events);

    $page->navigate('/')
        ->assertSee('Yoga with [name removed]')
        ->assertDontSee(SREV_YOGA)
        ->assertNoJavaScriptErrors();
});

it('F19: Approve all like this publishes the weekly Yoga sessions; an urgent update leads the queue', function () {
    $tc = srevTownCouncil($this->townHall);

    $page = visit(Uat::reviewPath(srevYogaDraft('2026-10-18')))
        ->waitForText(SREV_YOGA);

    srevConfirm($page, 'Approve all like this', 'Approve all drafts like this?', 'Approve all')
        ->waitForText('Approved 3 of 3.');

    $yoga = EventDraft::query()->where('title', SREV_YOGA)->orderBy('start_date')->get();
    expect($yoga->pluck('review_status')->all())->toBe(array_fill(0, 3, ReviewStatus::Approved));

    $page->navigate('/')
        ->assertCount('main >> article:has-text("'.SREV_YOGA.'")', 3)
        ->assertSeeIn('article:has(time[datetime^="2026-10-18"]):has-text("'.SREV_YOGA.'")', 'via Town Council');

    // The source moves Sunday's sessions an hour later: tomorrow's (within
    // 48 hours) is urgent, next week's is not.
    $fixture = Uat::feedFixture(Uat::TOWN_COUNCIL_FEED);
    $feed = srevMoveLater($fixture, SREV_YOGA_18_OCT, '20261018', '100000', '110000', '110000', '120000');
    $feed = srevMoveLater($feed, SREV_YOGA_25_OCT, '20261025', '100000', '110000', '110000', '120000');
    Uat::serveFeed(Uat::TOWN_COUNCIL_FEED, $feed);
    Uat::runSource($tc);

    $urgent = srevYogaDraft('2026-10-18', DraftKind::Update);
    srevYogaDraft('2026-10-25', DraftKind::Update);

    $page->navigate('/admin/event-drafts')
        ->waitForText('Urgent');

    $rows = $page->script(<<<'JS'
        () => [...document.querySelectorAll('tr.fi-ta-row')].map((tr) => tr.innerText.replace(/\s+/g, ' '))
    JS);

    // Urgent first, ahead of sooner new drafts (Book Sale today at 9am).
    expect($rows[0])->toContain('Urgent')->toContain('Update')->toContain(SREV_YOGA)
        ->and(collect($rows)->filter(fn (string $row): bool => str_contains($row, 'Urgent'))->count())->toBe(1);

    $page->click('tr.fi-ta-row >> nth=0 >> text="'.SREV_YOGA.'"')
        ->waitForText('Changes from Attleborough Town Council')
        ->assertPathIs(Uat::reviewPath($urgent))
        ->assertSee('Urgent')
        ->assertNoJavaScriptErrors();
});
