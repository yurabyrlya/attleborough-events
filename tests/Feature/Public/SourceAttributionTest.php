<?php

use App\Enums\RunStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Publishing\Actions\LinkEventSource;
use App\Support\PublicUrl;
use Database\Seeders\CategorySeeder;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use function Pest\Laravel\get;

/*
 * LEG-04, PIPE-09 (display), D-12, D-14, D-16, D-17: discovered events name
 * and link their source on the event page ("Listed by", "Also listed by",
 * "Read more at …") and on the calendar card ("via {short name}"), never
 * show feed organiser details, and load the attribution without per-card
 * queries.
 */

const ATTRIB_BAPTIST_ICS = 'https://www.attleborough.church/Calendar/Calendar.ics';

const ATTRIB_BAPTIST_EVENTS_PAGE = 'https://www.attleborough.church/Calendar/Month.aspx';

/**
 * The Baptist Church source, live, with the fixture served offline.
 */
function attribBaptistSource(): Source
{
    ingestFeed(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'));

    return Source::factory()->ics()->active()->create([
        'name' => 'Attleborough Baptist Church',
        'short_name' => 'Baptist Church',
        'url' => ATTRIB_BAPTIST_ICS,
        'events_page_url' => ATTRIB_BAPTIST_EVENTS_PAGE,
    ]);
}

/**
 * The earliest draft with this title from the latest run.
 */
function attribDraft(string $title): EventDraft
{
    return EventDraft::query()
        ->whereNotNull('source_item_id')
        ->where('title', $title)
        ->orderBy('start_date')
        ->orderBy('id')
        ->firstOrFail();
}

/**
 * A published event (factory, test-only) with one upcoming occurrence.
 *
 * @param  array<string, mixed>  $attributes
 */
function attribEvent(array $attributes = [], string $start = '2026-10-31 19:30'): Event
{
    return makeOccurrence($attributes, $start)->event;
}

/**
 * A source (factory) with the given name, short name and events page.
 *
 * @param  array<string, mixed>  $attributes
 */
function attribSource(string $name, array $attributes = []): Source
{
    return Source::factory()->ics()->active()->create([
        'name' => $name,
        'short_name' => $name,
        'events_page_url' => 'https://'.str($name)->slug().'.example.org/events',
        ...$attributes,
    ]);
}

/**
 * The visible text of the event page's <main>, whitespace collapsed.
 */
function attribText(string $html): string
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    return trim((string) preg_replace('/\s+/u', ' ', (string) $document->querySelector('main')?->textContent));
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);
});

it('credits and links the source of an approved Baptist Church draft on the event page and its card', function () {
    $source = attribBaptistSource();
    expect(ingestLiveRun($source)->status)->toBe(RunStatus::Succeeded);

    $draft = attribDraft('Evening Bible School');
    $itemUrl = 'https://www.attleborough.church/Calendar/Month.aspx?show_event=5834457';
    $event = ingestApprove($draft);

    $response = get(PublicUrl::eventPath($event))->assertOk();
    $text = attribText((string) $response->getContent());

    assertLink($response, $itemUrl, 'Attleborough Baptist Church');
    assertLink($response, $itemUrl, 'Read more at Attleborough Baptist Church');

    expect($text)->toContain('Listed by Attleborough Baptist Church')
        ->and($text)->toContain('Working through the Bible book by book')
        ->and($text)->not->toContain('More information:')
        ->and($text)->not->toContain("Listed by What's On Attleborough.")
        ->and((string) $response->getContent())->toMatch('#<p class="description[^"]*">[^<]*</p>\s*<p class="read-more[^"]*">#')
        ->and(substr_count((string) $response->getContent(), 'rel="nofollow noopener"'))->toBeGreaterThanOrEqual(2);

    $card = collect(cardsIn(get('/')->assertOk()))->first(fn (string $card): bool => cardTitle($card) === 'Evening Bible School');

    expect($card)->not->toBeNull()
        ->and($card)->toContain('via Baptist Church')
        ->and($card)->not->toContain('Source:');
});

it('links to the source events page when the item has no URL of its own', function () {
    $source = attribSource('Attleborough Heritage Group', ['short_name' => 'Heritage Group']);
    $event = attribEvent(['title' => 'Coffee and Chat', 'source_url' => null]);

    app(LinkEventSource::class)($event, $source, null, 'approved', ingestAdmin());

    $response = get(PublicUrl::eventPath($event))->assertOk();

    assertLink($response, $source->events_page_url, 'Attleborough Heritage Group');
    assertLink($response, $source->events_page_url, 'Read more at Attleborough Heritage Group');

    $card = cardsIn(get('/')->assertOk())[0];

    expect($card)->toContain('via Heritage Group');
});

it('uses the source name on the card when the short name is empty', function () {
    $source = attribSource('Attleborough Players', ['short_name' => '']);
    $event = attribEvent(['title' => 'Panto', 'source_url' => null]);

    app(LinkEventSource::class)($event, $source, null, 'approved', ingestAdmin());

    expect(cardsIn(get('/')->assertOk())[0])->toContain('via Attleborough Players');
});

it('renders the calendar with the same number of queries for 10 discovered events as for 1', function () {
    $admin = ingestAdmin();
    $link = function (int $n) use ($admin): void {
        $source = attribSource("Source {$n}");
        $event = attribEvent(['title' => "Event {$n}", 'source_url' => null], sprintf('2026-10-%02d 19:30', 18 + $n));
        app(LinkEventSource::class)($event, $source, null, 'approved', $admin);
    };

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        get('/')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $link(1);
    $one = $count();

    foreach (range(2, 10) as $n) {
        $link($n);
    }

    $cards = cardsIn(get('/')->assertOk());

    expect($cards)->toHaveCount(10)
        ->and($count())->toBe($one);
});

it('lists the primary source first and the others as "Also listed by" in link order', function () {
    $admin = ingestAdmin();
    $event = attribEvent(['title' => 'Remembrance Parade', 'source_url' => null]);
    $a = attribSource('Source A');
    $b = attribSource('Source B');
    $c = attribSource('Source C');

    foreach ([$a, $b, $c] as $source) {
        app(LinkEventSource::class)($event, $source, null, EventSource::VIA_APPROVED, $admin);
    }

    $response = get(PublicUrl::eventPath($event))->assertOk();
    $text = attribText((string) $response->getContent());

    expect($text)->toContain('Listed by Source A')
        ->and($text)->toContain('Also listed by Source B, Source C');

    assertLink($response, $b->events_page_url, 'Source B');
    assertLink($response, $c->events_page_url, 'Source C');
    expect(cardsIn(get('/')->assertOk())[0])->toContain('via Source A');
});

it('orders "Also listed by" by linked_at, then id', function () {
    $admin = ingestAdmin();
    $event = attribEvent(['title' => 'Coffee Morning', 'source_url' => null]);
    $sources = collect(['Source A', 'Source B', 'Source C', 'Source D'])->map(fn (string $name): Source => attribSource($name));

    foreach ($sources as $source) {
        app(LinkEventSource::class)($event, $source, null, EventSource::VIA_APPROVED, $admin);
    }

    $link = fn (Source $source): EventSource => EventSource::query()->where('event_id', $event->id)->where('source_id', $source->id)->sole();
    // Test setup: B linked last; C and D at the same instant (tie → id order).
    $link($sources[1])->forceFill(['linked_at' => now()->addHour()->utc()])->save();
    $link($sources[2])->forceFill(['linked_at' => now()->addMinute()->utc()])->save();
    $link($sources[3])->forceFill(['linked_at' => now()->addMinute()->utc()])->save();

    $text = attribText((string) get(PublicUrl::eventPath($event))->assertOk()->getContent());

    expect($text)->toContain('Listed by Source A')
        ->and($text)->toContain('Also listed by Source C, Source D, Source B');
});

it('credits a source linked only as a duplicate, then hands the credit to the organiser\'s own site', function () {
    $admin = ingestAdmin();
    $event = attribEvent(['title' => 'Coffee and Chat', 'source_url' => null]);
    $townCouncil = attribSource('Attleborough Town Council');
    $heritage = attribSource('Attleborough Heritage Group', ['is_organiser_source' => true]);

    app(LinkEventSource::class)($event, $townCouncil, null, EventSource::VIA_DUPLICATE, $admin);

    $text = attribText((string) get(PublicUrl::eventPath($event))->assertOk()->getContent());

    expect($text)->toContain('Listed by Attleborough Town Council')
        ->and($text)->not->toContain('Also listed by');

    app(LinkEventSource::class)($event, $heritage, null, EventSource::VIA_APPROVED, $admin);

    $response = get(PublicUrl::eventPath($event))->assertOk();
    $text = attribText((string) $response->getContent());

    expect($text)->toContain('Listed by Attleborough Heritage Group')
        ->and($text)->toContain('Also listed by Attleborough Town Council')
        ->and($text)->toContain('Read more at Attleborough Heritage Group');

    assertLink($response, $heritage->events_page_url, 'Read more at Attleborough Heritage Group');
    expect(cardsIn(get('/')->assertOk())[0])->toContain('via Attleborough Heritage Group');
});

it('shows no source credit and keeps the fallback for an event with no source link', function () {
    $event = attribEvent(['title' => 'Quiz Night', 'source_url' => null, 'source_note' => null]);

    $text = attribText((string) get(PublicUrl::eventPath($event))->assertOk()->getContent());
    $card = cardsIn(get('/')->assertOk())[0];

    expect($text)->toContain("Listed by What's On Attleborough.")
        ->and(substr_count($text, 'Listed by'))->toBe(1)
        ->and($text)->not->toContain('Also listed by')
        ->and($text)->not->toContain('Read more at')
        ->and($card)->not->toContain('via ');
});

it('never links a javascript: item URL and falls back to the events page, or to plain text', function () {
    $admin = ingestAdmin();
    $withPage = attribSource('Source With Page');
    $withoutPage = attribSource('Source Without Page', ['events_page_url' => null]);
    $event = attribEvent(['title' => 'Carnival', 'source_url' => null]);

    app(LinkEventSource::class)($event, $withPage, null, EventSource::VIA_APPROVED, $admin);
    app(LinkEventSource::class)($event, $withoutPage, null, EventSource::VIA_APPROVED, $admin);
    // Test setup: a hostile item URL stored on both links.
    EventSource::query()->where('event_id', $event->id)->update(['item_url' => 'javascript:alert(1)']);

    $response = get(PublicUrl::eventPath($event))->assertOk();
    $html = (string) $response->getContent();
    $text = attribText($html);

    expect($html)->not->toContain('javascript:')
        ->and($text)->toContain('Listed by Source With Page')
        ->and($text)->toContain('Also listed by Source Without Page');

    assertLink($response, $withPage->events_page_url, 'Source With Page');
    assertLink($response, $withPage->events_page_url, 'Read more at Source With Page');

    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $anchors = collect(iterator_to_array($document->querySelectorAll('a')))
        ->filter(fn (Element $a): bool => trim((string) $a->textContent) === 'Source Without Page');

    expect($anchors)->toHaveCount(0);
});

it('never shows organiser names, emails or phone numbers from a feed', function () {
    ingestFeed(implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Test//Attribution//EN',
        'BEGIN:VEVENT',
        'UID:privacy-1@example.org',
        'DTSTART:20261024T090000Z',
        'DTEND:20261024T110000Z',
        'SUMMARY:Community Yoga',
        'DESCRIPTION:Gentle yoga for all. Book with Jo on jo@example.org or call 01953 452123.',
        'ORGANIZER;CN=Jo Bloggs:mailto:jo@example.org',
        'LOCATION:Town Hall',
        'URL:https://attleboroughtc.example.org/event/community-yoga/2026-10-24',
        'END:VEVENT',
        'END:VCALENDAR',
        '',
    ]));

    $source = Source::factory()->ics()->active()->create([
        'name' => 'Attleborough Town Council',
        'short_name' => 'Town Council',
        'url' => 'https://attleboroughtc.example.org/?post_type=tribe_events&ical=1',
        'events_page_url' => 'https://attleboroughtc.example.org/events/',
    ]);
    expect(ingestLiveRun($source)->status)->toBe(RunStatus::Succeeded);

    $event = ingestApprove(attribDraft('Community Yoga'));

    $page = (string) get(PublicUrl::eventPath($event))->assertOk()->getContent();
    $card = collect(cardsIn(get('/')->assertOk()))->first(fn (string $card): bool => cardTitle($card) === 'Community Yoga');

    expect($card)->not->toBeNull()
        ->and($card)->toContain('via Town Council');

    foreach (['Jo Bloggs', 'jo@example.org', '01953 452123', '01953452123'] as $private) {
        expect($page)->not->toContain($private)
            ->and($card)->not->toContain($private);
    }

    expect(attribText($page))->toContain('Listed by Attleborough Town Council')
        ->and(attribText($page))->toContain('Gentle yoga for all.');
});
