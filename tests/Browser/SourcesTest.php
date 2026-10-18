<?php

use App\Enums\DraftKind;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceRun;
use App\Models\Venue;
use Database\Seeders\SourceSeeder;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F15 (Sources: register an ICS source, Test run preview,
 * Create drafts, Run now, Pause, run log, health) and F20 (public
 * attribution: Listed by, Also listed by, Read more at, card "via"),
 * docs/uat/browser-uat.md. Offline: Uat::fakeIngestion() serves the
 * captured feeds from tests/Fixtures/sources, robots.txt allows all, hosts
 * resolve through the fake HostResolver and Sleep is faked. Queued runs
 * are worked off the real database queue (Uat::workQueue()).
 */
beforeEach(function () {
    freezeLondon('2026-10-17 06:00');
    Uat::seedCategories();
    Uat::fakeIngestion();

    // Reference data the admin keeps: the church's venue, known by the
    // name its feed uses, so its drafts arrive with a confirmed venue.
    $this->church = Venue::factory()->create([
        'name' => 'Attleborough Baptist Church',
        'aliases' => ['Church Building'],
    ]);

    $this->admin = Uat::admin();
    actingAs($this->admin);
});

/** A second (synthetic) calendar that lists one of the church's sessions. */
const SOURCES_TOGETHER_FEED = 'https://churches-together.example.org/calendar.ics';

/**
 * A one-item calendar repeating the Baptist fixture's VEVENT with $uid
 * under a UID of its own, as another listing site would publish it.
 */
function sourcesSecondListing(string $uid): string
{
    preg_match('/BEGIN:VEVENT\r?\n(?:(?!END:VEVENT).)*?'.preg_quote($uid, '/').'.*?END:VEVENT\r?\n/s', Uat::feedFixture(Uat::BAPTIST_FEED), $block);

    expect($block)->not->toBeEmpty("No VEVENT with {$uid}");

    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//UAT//Churches Together//EN\r\n"
        .str_replace($uid, 'UID:uat-churches-together@example.org', $block[0])
        ."END:VCALENDAR\r\n";
}

/**
 * Click a header action, then its dialog's submit button. The previous
 * toast is closed first: it covers the header actions.
 */
function sourcesConfirm(mixed $page, string $label, string $heading, string $submit): mixed
{
    Uat::dismissNotifications($page);

    $page->click(Uat::role('button', $label))
        ->waitForText($heading);

    return $page->click(Uat::OPEN_MODAL.' >> '.Uat::role('button', $submit));
}

/**
 * The run log rows (the edit page's only Filament table), once the lazy
 * relation manager has rendered $count of them.
 */
function sourcesWaitForRunLog(mixed $page, int $count): mixed
{
    return Uat::waitUntil($page, "document.querySelectorAll('tr.fi-ta-row').length === {$count}", 15.0);
}

it('F15: registers an ICS source, previews it, creates drafts, runs it now and pauses it', function () {
    $page = visit('/admin');

    $page->click(Uat::roleContaining('link', 'Sources'))
        ->waitForText('New source')
        ->assertPathIs('/admin/sources')
        ->click(Uat::role('link', 'New source'))
        ->waitForText('Feed URL');

    // The "?" next to a field opens a dialog explaining it.
    $page->click(Uat::role('button', 'Help: Feed URL'))
        ->waitForText('Paste it in a browser: it should download a file starting with BEGIN:VCALENDAR')
        ->assertSeeIn(Uat::OPEN_MODAL, 'The calendar file (ICS) address the crawler downloads.')
        ->click(Uat::OPEN_MODAL.' >> button:has-text("Close")');
    Uat::waitForModalClosed($page);

    $page->fill('[id="form.name"]', 'Attleborough Baptist Church')
        ->fill('[id="form.short_name"]', 'Baptist Church')
        ->fill('[id="form.url"]', Uat::BAPTIST_FEED);

    Uat::settleFields($page, [
        'form.name' => 'Attleborough Baptist Church',
        'form.short_name' => 'Baptist Church',
        'form.url' => Uat::BAPTIST_FEED,
    ])->assertValue('[id="form.parser_config.identity"]', 'uid')
        ->click(Uat::role('button', 'Create'))
        ->waitForText('No test run yet');

    $source = Source::query()->sole();
    expect($source->is_active)->toBeFalse()
        ->and($source->slug)->toBe('attleborough-baptist-church');
    $page->assertPathIs('/admin/sources/'.$source->getRouteKey().'/edit')
        ->assertNotPresent(Uat::role('button', 'Create drafts'))
        ->assertNotPresent(Uat::role('button', 'Run now'));

    // Test run: a preview that drafts nothing.
    sourcesConfirm($page, 'Test run', 'Run a test? Nothing is drafted.', 'Run test')
        ->waitForText('Test run started');
    Uat::workQueue();

    $preview = SourceRun::query()->sole();
    $wouldCreate = (int) $preview->preview['would_create'];
    expect($preview->mode)->toBe(RunMode::Preview)
        ->and($preview->status)->toBe(RunStatus::Succeeded)
        ->and($wouldCreate)->toBeGreaterThan(0)
        ->and(EventDraft::query()->count())->toBe(0);

    // The panel polls while the run is active, so the result arrives without a reload.
    Uat::waitForPageText($page, "Would create {$wouldCreate} drafts");
    $page->assertSee('Beyond 90 days')
        ->assertSeeIn('tr:has-text("Women\'s Creative Club"):has-text("Sat 31 Oct, 10am")', 'Would draft');

    // Create drafts: the source goes live and the preview's items become drafts.
    Uat::waitUntil($page, 'Boolean(document.evaluate(\'//button[normalize-space()="Create drafts"]\', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE).singleNodeValue)', 15.0);
    sourcesConfirm($page, 'Create drafts', 'Create drafts and check this source daily?', 'Create drafts')
        ->waitForText('Drafts are being created');
    Uat::workQueue();

    expect($source->refresh()->is_active)->toBeTrue()
        ->and(EventDraft::query()->where('source_id', $source->id)->where('kind', DraftKind::New)->count())->toBe($wouldCreate);

    $page->script('() => window.scrollTo(0, document.body.scrollHeight)');
    sourcesWaitForRunLog($page, 2);
    $page->assertSee('Activation')
        ->assertPresent(Uat::role('button', 'Run now'))
        ->assertPresent(Uat::role('button', 'Pause'));

    // Run now: a third run in the log.
    sourcesConfirm($page, 'Run now', 'Check this source now?', 'Run now')
        ->waitForText('Check started');
    Uat::workQueue();

    expect(SourceRun::query()->where('trigger', RunTrigger::Manual)->where('mode', RunMode::Live)->sole()->status)
        ->toBe(RunStatus::Succeeded);
    sourcesWaitForRunLog($page, 3);

    // Pause: no longer checked; Run now and Pause go away.
    sourcesConfirm($page, 'Pause', 'Pause this source?', 'Pause')
        ->waitForText('Source paused');
    Uat::waitUntil($page, 'document.evaluate(\'//button[normalize-space()="Run now"]\', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE).singleNodeValue === null');
    $page->assertPresent(Uat::role('button', 'Test run'))
        ->assertNoJavaScriptErrors();

    expect($source->refresh()->is_active)->toBeFalse()
        ->and($source->next_check_at)->toBeNull();

    // Sources table: healthy, and the drafts wait in the review queue.
    $page->navigate('/admin/sources')
        ->waitForText('Attleborough Baptist Church')
        ->assertSeeIn('tr:has-text("Attleborough Baptist Church")', 'Healthy');

    $page->navigate('/admin/event-drafts')
        ->waitForText('Evening Bible School')
        ->assertSeeIn('tr.fi-ta-row:has-text("Evening Bible School") >> nth=0', 'Attleborough Baptist Church')
        ->assertNoJavaScriptErrors();
});

it('F15: a seeded source previews its all-day items as dates without a time', function () {
    $this->seed(SourceSeeder::class);
    $academy = Source::query()->where('slug', 'attleborough-academy')->sole();

    $page = visit('/admin/sources')
        ->waitForText('Attleborough Academy')
        ->click('tr:has-text("Attleborough Academy") >> text=Attleborough Academy')
        ->waitForText('No test run yet')
        ->assertPathIs('/admin/sources/'.$academy->getRouteKey().'/edit');

    sourcesConfirm($page, 'Test run', 'Run a test? Nothing is drafted.', 'Run test')
        ->waitForText('Test run started');
    Uat::workQueue();

    Uat::waitForPageText($page, 'Would create');

    // An all-day item shows its date only; a timed one its date and time.
    $cells = $page->script(<<<'JS'
        () => [...document.querySelectorAll('tr')]
            .filter((tr) => ['Autumn Half-Term Holiday', 'Parent Council Meeting'].includes(tr.cells[0]?.innerText.trim()))
            .reduce((found, tr) => ({ [tr.cells[0].innerText.trim()]: tr.cells[1].innerText.trim(), ...found }), {})
    JS);

    expect($cells)->toEqual([
        'Autumn Half-Term Holiday' => 'Mon 19 Oct',
        'Parent Council Meeting' => 'Thu 12 Nov, 6pm',
    ]);

    $page->assertNoJavaScriptErrors();

    expect(EventDraft::query()->count())->toBe(0)
        ->and($academy->refresh()->is_active)->toBeFalse();
});

it('F20: a discovered event credits its source on the event page and the card', function () {
    $baptist = Uat::ingestedSource('attleborough-baptist-church');

    $draft = EventDraft::query()
        ->where('source_id', $baptist->id)
        ->where('title', "Women's Creative Club")
        ->where('start_date', '2026-10-31')
        ->sole();
    expect($draft->venue_id)->toBe($this->church->id);

    // The admin approves it on its review page.
    $page = visit(Uat::reviewPath($draft))
        ->waitForText("Women's Creative Club")
        ->click(Uat::role('button', 'Approve & next (A)'))
        ->waitForText('Publish this event?')
        ->click(Uat::OPEN_MODAL.' >> '.Uat::role('button', 'Publish'))
        ->waitForText('Published');

    $event = $draft->refresh()->event;
    expect($event)->not->toBeNull();

    // A second listing of the same session (another calendar, its own UID)
    // is flagged as a duplicate and marked as one: nothing new is published,
    // and the event now credits both sources.
    $itemUrl = 'https://www.attleborough.church/Calendar/Month.aspx?show_event=5954098';
    Uat::serveFeed(SOURCES_TOGETHER_FEED, sourcesSecondListing('UID:E5954098@UK.endis.com'));

    $together = Source::factory()->ics()->active()->create([
        'name' => 'UAT Churches Together',
        'short_name' => 'Churches Together',
        'url' => SOURCES_TOGETHER_FEED,
        'events_page_url' => 'https://churches-together.example.org/whats-on',
    ]);
    Uat::runSource($together);

    $listing = EventDraft::query()->where('source_id', $together->id)->sole();
    expect($listing->duplicate_of_event_id)->toBe($event->id);

    $page->navigate(Uat::reviewPath($listing))
        ->waitForText('Possible duplicate')
        ->click(Uat::role('button', 'Mark as duplicate'))
        ->waitForText('Mark as duplicate?')
        ->click(Uat::OPEN_MODAL.' >> '.Uat::role('button', 'Mark as duplicate'))
        ->waitForText('Marked as duplicate');

    // The event page: "Listed by" the organiser's own calendar (linked to
    // the item's page), "Also listed by" the other, and "Read more at".
    $page->navigate('/events/'.$event->slug)
        ->waitForText("Women's Creative Club")
        ->assertSeeIn('#who-heading', "Who's running it")
        ->assertSee('Listed by Attleborough Baptist Church')
        ->assertAttribute('.listed-by-source >> '.Uat::role('link', 'Attleborough Baptist Church'), 'href', $itemUrl)
        ->assertSee('Also listed by UAT Churches Together')
        ->assertAttribute(Uat::role('link', 'Read more at Attleborough Baptist Church'), 'href', $itemUrl)
        ->assertDontSee("Listed by What's On Attleborough.")
        ->assertNoJavaScriptErrors();

    // The calendar card: a short "via" line.
    $page->navigate('/')
        ->assertSeeIn('article:has-text("Women\'s Creative Club")', 'via Baptist Church')
        ->assertNoJavaScriptErrors();

    expect(EventDraft::query()->where('source_id', $baptist->id)->whereNotNull('event_id')->count())->toBe(1);
});
