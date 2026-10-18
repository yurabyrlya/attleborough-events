<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RunMode;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Ingestion\Data\DuplicateMatch;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\DuplicateDetector;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Data\PublishableEventData;
use App\Review\DraftPublishability;
use Database\Seeders\CategorySeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * PIPE-05 (03-11): an ingested item that would become a new draft is
 * compared with published events (by occurrence) and with pending drafts
 * from other sources or origins. Same canonical URL → 'url' (1.000);
 * otherwise same London date, starts within 60 minutes (or both all-day),
 * same or similar venue and pg_trgm title similarity ≥ 0.3 → 'fuzzy'.
 * Offline: Http::fake through ingestFeed(), Sleep::fake.
 */

const DUP_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

function dupTcFixture(): string
{
    return sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
}

/**
 * A published event entered by hand (the manual path through PublishEvent),
 * at the named venue (reused when it exists).
 *
 * @param  array<string, mixed>  $overrides  PublishableEventData arguments
 */
function dupPublish(string $title, string $date = '2026-10-25', ?string $time = '10:00', string $venueName = 'Town Hall', array $overrides = []): Event
{
    $venue = Venue::query()->where('name', $venueName)->first() ?? Venue::factory()->create(['name' => $venueName]);

    return app(PublishEvent::class)(new PublishableEventData(...array_merge([
        'title' => $title,
        'description' => 'A friendly session for all.',
        'categoryId' => (int) Category::query()->orderBy('id')->value('id'),
        'venueId' => $venue->id,
        'startDate' => $date,
        'startTime' => $time,
        'organiserContact' => 'hello@example.test',
    ], $overrides)), ingestAdmin(), EventOrigin::Manual);
}

/**
 * @return Collection<int, EventDraft>
 */
function dupFlagged(): Collection
{
    return EventDraft::query()
        ->where(fn ($q) => $q->whereNotNull('duplicate_of_event_id')->orWhereNotNull('duplicate_of_draft_id'))
        ->orderBy('id')
        ->get();
}

function dupTcSource(): Source
{
    return Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => DUP_TC_URL,
    ]);
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->tc = dupTcSource();
    ingestFeed(dupTcFixture());
});

it('flags only the 25 Oct Town Council Yoga draft as a fuzzy duplicate of the published "Yoga with [name removed]"', function () {
    $event = dupPublish('Yoga with [name removed]');

    $run = ingestLiveRun($this->tc);
    expect($run->drafts_created)->toBeGreaterThan(0);

    $flagged = dupFlagged();

    expect($flagged)->toHaveCount(1);

    $draft = $flagged->sole();

    expect($draft->title)->toBe('[name removed] ~ Yoga')
        ->and($draft->start_date->toDateString())->toBe('2026-10-25')
        ->and($draft->duplicate_of_event_id)->toBe($event->id)
        ->and($draft->duplicate_of_draft_id)->toBeNull()
        ->and($draft->duplicate_reason)->toBe('fuzzy')
        ->and($draft->duplicate_score)->toBe('0.783')
        ->and($draft->event_id)->toBeNull();

    // The 18 and 21 Oct Yoga drafts (other dates) carry no warning.
    $otherYoga = EventDraft::query()->where('title', '[name removed] ~ Yoga')->where('start_date', '<>', '2026-10-25')->get();
    expect($otherYoga)->toHaveCount(2)
        ->and($otherYoga->every(fn (EventDraft $d): bool => $d->duplicate_of_event_id === null && $d->duplicate_reason === null))->toBeTrue();

    expect(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toContain('duplicate');
});

it('leaves a bare "Yoga" event alone: similarity 0.2777778 is under the 0.3 threshold', function () {
    dupPublish('Yoga');

    ingestLiveRun($this->tc);

    expect(dupFlagged())->toHaveCount(0)
        ->and(config('ingest.duplicate.title_threshold'))->toBe(0.3);
});

it('raises no warning when the whole Town Council fixture arrives in an empty database (Pitfall 6)', function () {
    $run = ingestLiveRun($this->tc);

    expect($run->drafts_created)->toBeGreaterThan(20)
        ->and(dupFlagged())->toHaveCount(0);
});

it('notes the possible duplicate on the D-01 preview row and writes nothing', function () {
    $event = dupPublish('Yoga with [name removed]');

    $run = ingestLiveRun($this->tc, RunMode::Preview);

    $rows = collect($run->preview['rows'])->filter(fn (array $row): bool => $row['duplicate'] !== null)->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('[name removed] ~ Yoga')
        ->and($rows[0]['starts'])->toBe('2026-10-25 10:00')
        ->and($rows[0]['duplicate'])->toEqual(['title' => $event->title, 'reason' => 'fuzzy', 'score' => '0.78'])
        ->and(EditSource::previewRow($rows[0])['duplicate'])->toBe('Possible duplicate of Yoga with [name removed]')
        ->and(EventDraft::query()->count())->toBe(0);
});

/*
 * Rule edges (Task 3): URL, threshold, tolerance, same source, empty,
 * ordering, precision and hidden candidates.
 */

const DUP_YOUTH_URL = 'https://youth.example.org/calendar.ics';

/**
 * A one-event ICS feed (London times, Town Hall) for a second source.
 */
function dupOneEventFeed(string $uid, string $summary, string $start, string $end, ?string $url = null): string
{
    $lines = [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Synthetic duplicate fixture//EN',
        'BEGIN:VTIMEZONE', 'TZID:Europe/London',
        'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0000', 'TZOFFSETTO:+0100', 'TZNAME:BST', 'DTSTART:19700329T010000', 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3', 'END:DAYLIGHT',
        'BEGIN:STANDARD', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0000', 'TZNAME:GMT', 'DTSTART:19701025T020000', 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10', 'END:STANDARD',
        'END:VTIMEZONE',
        'BEGIN:VEVENT', "UID:{$uid}", 'DTSTAMP:20261016T120000Z',
        "DTSTART;TZID=Europe/London:{$start}", "DTEND;TZID=Europe/London:{$end}",
        "SUMMARY:{$summary}", 'LOCATION:Town Hall',
        ...($url !== null ? ["URL:{$url}"] : []),
        'END:VEVENT', 'END:VCALENDAR',
    ];

    return implode("\r\n", $lines)."\r\n";
}

/**
 * Serves the Town Council fixture, and $youth for the second source's URL.
 */
function dupServeBoth(string $youth): void
{
    ingestFeed(fn (Request $request) => Http::response(
        str_starts_with($request->url(), 'https://youth.example.org/') ? $youth : dupTcFixture(),
        200,
        ['Content-Type' => 'text/calendar; charset=utf-8'],
    ));
}

function dupYogaDraft(): EventDraft
{
    return EventDraft::query()->where('title', '[name removed] ~ Yoga')->where('start_date', '2026-10-25')->sole();
}

function dupMeasured(string $a, string $b): float
{
    return (float) DB::selectOne('select similarity(lower(?), lower(?)) as s', [$a, $b])->s;
}

function dupFacts(array $overrides = []): NormalisedFacts
{
    return new NormalisedFacts(...array_merge([
        'title' => '[name removed] ~ Yoga',
        'allDay' => false,
        'startDate' => '2026-10-25',
        'startTime' => '10:00',
        'venueName' => 'Town Hall',
    ], $overrides));
}

it('matches the same canonical URL whatever the dates: reason url, score 1.000', function () {
    $event = dupPublish('Autumn wellbeing classes', '2026-11-05', '19:00', 'Library', [
        'sourceUrl' => 'https://www.AttleboroughTC.org.uk/event/name-removed-yoga/2026-10-25/?utm_source=newsletter#book',
    ]);

    ingestLiveRun($this->tc);

    $draft = dupYogaDraft();

    expect($draft->duplicate_of_event_id)->toBe($event->id)
        ->and($draft->duplicate_reason)->toBe('url')
        ->and($draft->duplicate_score)->toBe('1.000')
        ->and(dupFlagged())->toHaveCount(1);
});

it('matches a pending draft from another origin by canonical URL', function () {
    $manual = EventDraft::factory()->manual()->create([
        'title' => 'Wellbeing',
        'start_date' => '2026-11-05',
        'source_url' => 'https://attleboroughtc.org.uk/event/name-removed-yoga/2026-10-25/?fbclid=abc',
    ]);

    ingestLiveRun($this->tc);

    $draft = dupYogaDraft();

    expect($draft->duplicate_of_draft_id)->toBe($manual->id)
        ->and($draft->duplicate_of_event_id)->toBeNull()
        ->and($draft->duplicate_reason)->toBe('url')
        ->and($draft->duplicate_score)->toBe('1.000')
        ->and(app(DraftPublishability::class)->bulkBlockerKeys($draft))->toContain('duplicate');
});

it('counts a title similarity exactly at the threshold, and not one step above it', function () {
    $event = dupPublish('Yoga with [name removed]');
    $measured = dupMeasured('Yoga with [name removed]', '[name removed] ~ Yoga');

    expect($measured)->toBe(0.7826087);

    config()->set('ingest.duplicate.title_threshold', $measured);
    expect(app(DuplicateDetector::class)->find(dupFacts(), $this->tc, null, null)?->eventId)->toBe($event->id);

    config()->set('ingest.duplicate.title_threshold', $measured + 0.001);
    expect(app(DuplicateDetector::class)->find(dupFacts(), $this->tc, null, null))->toBeNull();
});

it('accepts starts exactly 60 minutes apart and refuses 61', function (string $time, bool $matches) {
    $event = dupPublish('Yoga with [name removed]', '2026-10-25', $time);

    ingestLiveRun($this->tc);

    expect(dupYogaDraft()->duplicate_of_event_id)->toBe($matches ? $event->id : null);
})->with([
    '60 minutes later' => ['11:00', true],
    '61 minutes later' => ['11:01', false],
    '60 minutes earlier' => ['09:00', true],
    '61 minutes earlier' => ['08:59', false],
]);

it('passes the time guard when both are all-day on the same date, and not when only one is', function () {
    $event = dupPublish('Yoga with [name removed]', '2026-10-25', null, 'Town Hall', ['allDay' => true]);
    $detector = app(DuplicateDetector::class);

    expect($detector->find(dupFacts(['allDay' => true, 'startTime' => null]), $this->tc, null, null)?->eventId)->toBe($event->id)
        ->and($detector->find(dupFacts(), $this->tc, null, null))->toBeNull()
        ->and($detector->find(dupFacts(['allDay' => true, 'startTime' => null, 'startDate' => '2026-10-26']), $this->tc, null, null))->toBeNull();
});

it('never flags Junior Youth and Senior Youth: same source, more than 60 minutes apart (0.7894737)', function () {
    expect(dupMeasured('Attleborough Youth Project ~ Junior Youth', 'Attleborough Youth Project ~ Senior Youth'))->toBe(0.7894737);

    ingestLiveRun($this->tc);

    expect(EventDraft::query()->where('title', 'like', 'Attleborough Youth Project%')->count())->toBeGreaterThan(4)
        ->and(dupFlagged())->toHaveCount(0);
});

it('keeps the time guard across sources: Senior Youth at 18:00 elsewhere is not Junior Youth at 15:45', function () {
    $youth = Source::factory()->ics()->active()->create(['name' => 'Youth Project', 'url' => DUP_YOUTH_URL]);
    dupServeBoth(dupOneEventFeed('senior-1019@youth.example.org', 'Attleborough Youth Project ~ Senior Youth', '20261019T180000', '20261019T200000'));

    ingestLiveRun($this->tc);

    // Only the Town Council's Junior Youth (15:45) is left pending that day.
    $seniorTc = EventDraft::query()->where('source_id', $this->tc->id)->where('title', 'Attleborough Youth Project ~ Senior Youth')->where('start_date', '2026-10-19')->sole();
    app(RejectDraft::class)($seniorTc, ingestAdmin());

    ingestLiveRun($youth);

    $senior = EventDraft::query()->where('source_id', $youth->id)->sole();

    expect($senior->duplicate_of_draft_id)->toBeNull()
        ->and($senior->duplicate_of_event_id)->toBeNull();
});

it('flags the same session listed by another source against the pending draft', function () {
    $youth = Source::factory()->ics()->active()->create(['name' => 'Youth Project', 'url' => DUP_YOUTH_URL]);
    dupServeBoth(dupOneEventFeed('senior-1019@youth.example.org', 'Attleborough Youth Project ~ Senior Youth', '20261019T180000', '20261019T200000'));

    ingestLiveRun($this->tc);
    ingestLiveRun($youth);

    $seniorTc = EventDraft::query()->where('source_id', $this->tc->id)->where('title', 'Attleborough Youth Project ~ Senior Youth')->where('start_date', '2026-10-19')->sole();
    $senior = EventDraft::query()->where('source_id', $youth->id)->sole();

    expect($senior->duplicate_of_draft_id)->toBe($seniorTc->id)
        ->and($senior->duplicate_reason)->toBe('fuzzy')
        ->and($senior->duplicate_score)->toBe('1.000');
});

it('checks an item without a URL by the fuzzy rule only, and never matches one with no venue', function () {
    $event = dupPublish('Yoga with [name removed]');
    $detector = app(DuplicateDetector::class);

    expect($detector->find(dupFacts(['url' => null]), $this->tc, null, null)?->reason)->toBe('fuzzy')
        ->and($detector->find(dupFacts(['url' => null, 'venueName' => null]), $this->tc, null, null))->toBeNull()
        ->and($detector->find(dupFacts(['venueName' => null]), $this->tc, null, $event->venue_id)?->eventId)->toBe($event->id);
});

it('stores the lower event id when two candidates score the same', function () {
    $first = dupPublish('Yoga with [name removed]');
    $second = dupPublish('Yoga with [name removed]');

    expect($first->id)->toBeLessThan($second->id);

    ingestLiveRun($this->tc);

    expect(dupYogaDraft()->duplicate_of_event_id)->toBe($first->id);
});

it('ranks by score: the closer title wins over an earlier event', function () {
    dupPublish('Yoga with [name removed] and friends');
    $closer = dupPublish('Yoga with [name removed]');

    ingestLiveRun($this->tc);

    expect(dupYogaDraft()->duplicate_of_event_id)->toBe($closer->id);
});

it('stores the score as numeric(4,3), rounding half away from zero, and shows two decimals', function () {
    dupPublish('Yoga with [name removed]');
    ingestLiveRun($this->tc);

    $stored = DB::selectOne('select duplicate_score::text as s from event_drafts where id = ?', [dupYogaDraft()->id])->s;

    expect($stored)->toBe('0.783')
        ->and(DuplicateMatch::formatScore($stored))->toBe('0.78')
        ->and(DuplicateMatch::formatScore(0.7826087))->toBe('0.78')
        ->and(DB::selectOne('select similarity(lower(?), lower(?))::numeric(4,3)::text as s', ['Attleborough Youth Project ~ Junior Youth', 'Attleborough Youth Project ~ Senior Youth'])->s)->toBe('0.789')
        ->and(DB::selectOne('select 0.7825::numeric(4,3)::text as s')->s)->toBe('0.783');
});

it('never offers a hidden event or a hidden occurrence as a candidate', function (string $what) {
    $event = dupPublish('Yoga with [name removed]');

    $what === 'event'
        ? app(ChangeEventStatus::class)($event, EventStatus::Hidden, ingestAdmin())
        : app(ChangeOccurrenceStatus::class)(EventOccurrence::query()->where('event_id', $event->id)->sole(), OccurrenceStatus::Hidden, ingestAdmin());

    ingestLiveRun($this->tc);

    expect(dupFlagged())->toHaveCount(0);
})->with(['event', 'occurrence']);

it('never matches candidates from the same source: drafts, linked events or its own replaced draft', function () {
    // The 25 Oct café item turned into a Yoga copy at 10:30 with the Yoga's URL.
    $feed = ingestEditEvent(dupTcFixture(), 'UID:10005543-1792929600-1792936800@attleboroughtc.org.uk', [
        'DTSTART;TZID=Europe/London:20261025T120000' => 'DTSTART;TZID=Europe/London:20261025T103000',
        'DTEND;TZID=Europe/London:20261025T140000' => 'DTEND;TZID=Europe/London:20261025T113000',
        'SUMMARY:The Chambers ~ Community Cafe' => 'SUMMARY:[name removed] ~ Yoga',
        "URL:https://attleboroughtc.org.uk/event/the-chambers-community-cafe-35/2026\r\n -10-25" => 'URL:https://attleboroughtc.org.uk/event/name-removed-yoga/2026-10-25',
    ]);
    ingestFeed($feed);

    ingestLiveRun($this->tc);

    expect(EventDraft::query()->where('title', '[name removed] ~ Yoga')->where('start_date', '2026-10-25')->count())->toBe(2)
        ->and(dupFlagged())->toHaveCount(0);

    // Approve the real Yoga session: its event is now linked to the Town
    // Council, and a re-check of the copy (moved 15 minutes, so its pending
    // draft is replaced) still finds nothing from its own source.
    ingestApprove(EventDraft::query()->where('title', '[name removed] ~ Yoga')->where('start_time', '10:00')->where('start_date', '2026-10-25')->sole());

    ingestFeed(str_replace('DTSTART;TZID=Europe/London:20261025T103000', 'DTSTART;TZID=Europe/London:20261025T104500', $feed));
    $run = ingestLiveRun($this->tc);

    $copy = EventDraft::query()->where('source_item_id', SourceItem::query()->where('external_id', 'like', '10005543%')->value('id'))->where('review_status', 'pending_review')->sole();

    expect($run->drafts_replaced)->toBeGreaterThan(0)
        ->and($copy->start_time)->toStartWith('10:45')
        ->and(dupFlagged())->toHaveCount(0);
});
