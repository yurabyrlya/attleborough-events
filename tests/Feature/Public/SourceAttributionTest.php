<?php

use App\Enums\RunStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Publishing\Actions\LinkEventSource;
use App\Support\PublicUrl;
use Database\Seeders\CategorySeeder;
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
