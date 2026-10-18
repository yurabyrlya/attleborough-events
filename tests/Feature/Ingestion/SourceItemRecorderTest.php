<?php

use App\Enums\FetchFailureKind;
use App\Enums\RunMode;
use App\Enums\RunStatus;
use App\Enums\RunTrigger;
use App\Ingestion\Health\SourceHealthTracker;
use App\Ingestion\Ics\IcsExtractor;
use App\Ingestion\IngestRun;
use App\Ingestion\Pipeline\TitleRules;
use App\Ingestion\RawBodyStore;
use App\Jobs\FetchSourceJob;
use App\Models\Category;
use App\Models\CategoryMapping;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceRun;
use App\Models\Venue;
use App\Review\CategoryAssigner;
use App\Review\DraftPublishability;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * Run outcomes and the recorder contract (03-05): every fetch outcome is a
 * run status, every found item lands in exactly one counter, and drafts
 * respect privacy (D-17), the excerpt rules (PIPE-09, D-21) and the
 * source's defaults (D-04, D-22). Offline: Http::fake, the Pest fake
 * HostResolver and Sleep::fake.
 */

const RECORDER_FEED_URL = 'https://feed.example.org/calendar.ics';

const RECORDER_TC_URL = 'https://attleboroughtc.org.uk/?post_type=tribe_events&ical=1';

/**
 * Fake every robots.txt with $robots and every other URL with $feed (a
 * response, or a closure taking the request).
 */
function recorderServe(mixed $feed, string $robots = "User-agent: *\nDisallow:\n"): void
{
    Http::fake(function (Request $request) use ($feed, $robots) {
        if (str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', '/robots.txt')) {
            return Http::response($robots, 200, ['Content-Type' => 'text/plain']);
        }

        return $feed instanceof Closure ? $feed($request) : $feed;
    });
}

function recorderIcs(string $body, array $headers = []): mixed
{
    return Http::response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', ...$headers]);
}

/**
 * An ICS body from VEVENT blocks (lists of content lines), CRLF endings.
 *
 * @param  list<list<string>>  $events
 */
function recorderCalendar(array $events): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//What\'s On Attleborough//Recorder test//EN'];

    foreach ($events as $event) {
        $lines = [...$lines, 'BEGIN:VEVENT', 'DTSTAMP:20261017T050000Z', ...$event, 'END:VEVENT'];
    }

    return implode("\r\n", [...$lines, 'END:VCALENDAR'])."\r\n";
}

function recorderRun(Source $source): SourceRun
{
    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $source->id, 'status' => RunStatus::Queued])->save();

    FetchSourceJob::dispatchSync($run->id);

    return $run->refresh();
}

function recorderBucketTotal(SourceRun $run): int
{
    return $run->items_new + $run->items_changed + $run->items_unchanged + $run->skipped_past
        + $run->skipped_far_future + $run->skipped_rule + $run->skipped_parse_error;
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    Storage::fake('local');
    freezeLondon('2026-10-17 06:00:00');
    $this->seed(CategorySeeder::class);

    $this->source = Source::factory()->ics()->active()->create([
        'name' => 'Example Community Centre',
        'url' => RECORDER_FEED_URL,
    ]);
});

afterEach(function () {
    // SRC-07 adjacency: every found item is counted in exactly one bucket.
    foreach (SourceRun::query()->get() as $run) {
        expect($run->items_found)->toBe(recorderBucketTotal($run), "Counter invariant broken for run {$run->id}");
    }
});

it('records a robots.txt refusal as a blocked run without items or drafts', function () {
    recorderServe(recorderIcs(icsFixture('series.ics')), "User-agent: *\nDisallow: /calendar.ics\n");

    $run = recorderRun($this->source);
    $source = $this->source->refresh();

    expect($run->status)->toBe(RunStatus::Blocked)
        ->and($run->failure_kind)->toBeNull()
        ->and($run->error_message)->not->toBeNull()
        ->and($run->raw_path)->toBeNull()
        ->and($run->items_found)->toBe(0)
        ->and(SourceItem::query()->count())->toBe(0)
        ->and(EventDraft::query()->count())->toBe(0)
        ->and($source->last_run_at)->not->toBeNull()
        ->and($source->last_success_at)->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === RECORDER_FEED_URL);
});

it('records a 304 as not_modified and touches no items', function () {
    $tcSource = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => RECORDER_TC_URL,
    ]);
    $etag = '"87260-1792262397;;;"';

    recorderServe(fn (Request $request) => $request->hasHeader('If-None-Match', $etag)
        ? Http::response('', 304, ['ETag' => $etag])
        : recorderIcs(sourceFixture('attleborough-town-council/2026-10-17-ical.ics'), ['ETag' => $etag]));

    $first = recorderRun($tcSource);
    $this->travel(30)->minutes();
    $second = recorderRun($tcSource->refresh());

    expect($first->status)->toBe(RunStatus::Succeeded)
        ->and($tcSource->refresh()->etag)->toBe($etag)
        ->and($second->status)->toBe(RunStatus::NotModified)
        ->and($second->items_found)->toBe(0)
        ->and($second->raw_path)->toBeNull()
        ->and(SourceItem::query()->where('last_seen_run_id', $second->id)->count())->toBe(0)
        ->and(SourceItem::query()->where('last_seen_run_id', $first->id)->count())->toBe($first->items_new);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('If-None-Match', $etag));
});

it('sends no conditional headers after a failed live run', function () {
    $this->source->forceFill(['etag' => '"old"'])->save();
    $feedRequests = 0;
    recorderServe(function () use (&$feedRequests) {
        return ++$feedRequests === 1 ? Http::response('', 500) : recorderIcs(icsFixture('series.ics'));
    });
    $failed = recorderRun($this->source);

    $this->travel(30)->minutes();
    $run = recorderRun($this->source->refresh());

    expect($failed->status)->toBe(RunStatus::Failed)
        ->and($run->status)->toBe(RunStatus::Succeeded);
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('If-None-Match'));
});

it('fails a bot challenge page as content_invalid, stores its body and keeps its text out of the message', function () {
    $page = '<!DOCTYPE html><html><head><title>Just a moment...</title></head><body>Checking your browser SECRET-PAGE-TEXT</body></html>';
    recorderServe(Http::response($page, 200, ['Content-Type' => 'text/html']));

    $run = recorderRun($this->source);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_kind)->toBe(FetchFailureKind::ContentInvalid->value)
        ->and($run->raw_path)->not->toBeNull()
        ->and(app(RawBodyStore::class)->get($this->source->id, $run->id))->toBe($page)
        ->and($run->raw_sha256)->toBe(hash('sha256', $page))
        ->and(mb_strlen((string) $run->error_message))->toBeLessThanOrEqual(500)
        ->and($run->error_message)->not->toContain('SECRET')
        ->and($run->error_message)->not->toContain('Just a moment')
        ->and(SourceItem::query()->count())->toBe(0)
        ->and(EventDraft::query()->count())->toBe(0);
});

it('fails a server error as http_status', function () {
    recorderServe(Http::response('Internal error SECRET-PAGE-TEXT', 500, ['Content-Type' => 'text/plain']));

    $run = recorderRun($this->source);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_kind)->toBe(FetchFailureKind::HttpStatus->value)
        ->and($run->http_status)->toBe(500)
        ->and($run->error_message)->not->toContain('SECRET')
        ->and($this->source->refresh()->last_success_at)->toBeNull()
        ->and(EventDraft::query()->count())->toBe(0);
});

it('fails an unreadable calendar as parse_error', function () {
    recorderServe(recorderIcs("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:Cut off\r\n"));

    $run = recorderRun($this->source);

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_kind)->toBe(FetchFailureKind::ParseError->value)
        ->and($run->raw_path)->not->toBeNull()
        ->and($run->items_found)->toBe(0)
        ->and(EventDraft::query()->count())->toBe(0);
});

it('puts the run back to queued and releases the job when the host is busy', function () {
    DB::table('crawl_hosts')->insert([
        'host' => 'feed.example.org',
        'next_allowed_at' => CarbonImmutable::now()->addHour()->utc(),
        'last_request_at' => CarbonImmutable::now()->utc(),
        'robots_status' => 'allow_all',
        'robots_fetched_at' => CarbonImmutable::now()->utc(),
        'created_at' => CarbonImmutable::now()->utc(),
        'updated_at' => CarbonImmutable::now()->utc(),
    ]);
    Http::fake();

    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $this->source->id, 'status' => RunStatus::Queued])->save();

    $job = (new FetchSourceJob($run->id))->withFakeQueueInteractions();
    $job->handle(app(IngestRun::class), app(SourceHealthTracker::class));

    $job->assertReleased();
    expect($run->refresh()->status)->toBe(RunStatus::Queued)
        ->and($run->started_at)->toBeNull()
        ->and($run->finished_at)->toBeNull();
    Http::assertNothingSent();
});

it('keeps the job settings that stop polite waits counting as failures', function () {
    $job = new FetchSourceJob(1);

    expect($job->uniqueFor)->toBe(900)
        ->and($job->timeout)->toBe(75)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->connection)->toBe('database')
        ->and($job->queue)->toBe('default')
        ->and($job->retryUntil()->getTimestamp())->toBe(CarbonImmutable::now()->addHours(6)->getTimestamp());
});

it('marks the run failed/abandoned and rethrows on an unexpected error', function () {
    recorderServe(recorderIcs(icsFixture('series.ics')));
    $this->app->instance(RawBodyStore::class, new class extends RawBodyStore
    {
        public function put(int $sourceId, int $runId, string $body): string
        {
            throw new RuntimeException('disk full SECRET');
        }
    });

    $run = new SourceRun(['mode' => RunMode::Live, 'trigger' => RunTrigger::Manual]);
    $run->forceFill(['source_id' => $this->source->id, 'status' => RunStatus::Queued])->save();

    expect(fn () => FetchSourceJob::dispatchSync($run->id))->toThrow(RuntimeException::class, 'disk full');

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->failure_kind)->toBe(FetchFailureKind::Abandoned->value)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->not->toContain('SECRET')
        ->and(EventDraft::query()->count())->toBe(0);
});

it('applies title rules, the D-21 fallback and the D-22 default venue to the Town Council feed', function () {
    $townHall = Venue::factory()->create(['name' => 'Town Hall', 'postcode' => 'NR17 9ZZ']);
    $tcSource = Source::factory()->ics()->active()->withIdentity('tec_post_id')->create([
        'name' => 'Attleborough Town Council',
        'url' => RECORDER_TC_URL,
        'default_venue_id' => $townHall->id,
        'title_rules' => [['mode' => TitleRules::MODE_SKIP, 'keyword' => 'council meeting']],
    ]);
    $body = sourceFixture('attleborough-town-council/2026-10-17-ical.ics');
    recorderServe(recorderIcs($body));

    $expectedSkipped = collect(app(IcsExtractor::class)->extract($body, $tcSource)->items)
        ->filter(fn ($item): bool => $item->facts !== null
            && CategoryAssigner::titleContainsKeyword($item->facts->title, 'council meeting'))
        ->count();

    $run = recorderRun($tcSource);
    $drafts = EventDraft::query()->where('source_id', $tcSource->id)->get();

    expect($expectedSkipped)->toBeGreaterThan(0)
        ->and($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->items_found)->toBe(30)
        ->and($run->skipped_rule)->toBe($expectedSkipped)
        ->and($drafts)->toHaveCount($run->items_new)
        ->and($drafts->contains(fn (EventDraft $d): bool => CategoryAssigner::titleContainsKeyword($d->title, 'council meeting')))->toBeFalse()
        ->and($drafts->where('venue_name', 'Town Hall'))->not->toBeEmpty()
        ->and($drafts->where('venue_name', '!=', 'Town Hall'))->not->toBeEmpty();

    foreach ($drafts as $draft) {
        expect($draft->description)->toBe('Details from Attleborough Town Council.')
            ->and(app(DraftPublishability::class)->blockers($draft->fresh()))->not->toContain('Add a description.')
            ->and($draft->venue_id)->toBe($draft->venue_name === 'Town Hall' ? $townHall->id : null);
    }
});

it('never copies the feed organiser and strips contacts from the excerpt', function () {
    $venue = Venue::factory()->create();
    $this->source->forceFill(['default_venue_id' => $venue->id])->save();
    recorderServe(recorderIcs(recorderCalendar([[
        'UID:org-1@synthetic.invalid',
        'SUMMARY:Coffee Morning',
        'DTSTART:20261024T100000',
        'DTEND:20261024T120000',
        'ORGANIZER;CN=Jo Bloggs:mailto:jo@example.org',
        'DESCRIPTION:Cake and chat. Call 01953 452123 or email jo@example.org to help.',
    ]])));

    $run = recorderRun($this->source->refresh());
    $draft = EventDraft::query()->sole();

    expect($run->drafts_created)->toBe(1)
        ->and($draft->organiser_name)->toBeNull()
        ->and($draft->organiser_contact)->toBeNull()
        ->and($draft->organiser_website)->toBeNull()
        ->and($draft->contact_public)->toBeFalse()
        ->and($draft->description)->not->toContain('01953')
        ->and($draft->description)->not->toContain('@')
        ->and($draft->description)->not->toContain('Jo Bloggs')
        ->and($draft->description)->toStartWith('Cake and chat.')
        ->and($draft->raw_payload['organiser'])->toBe(['name' => 'Jo Bloggs', 'email' => 'jo@example.org'])
        ->and($draft->raw_payload['description'])->toContain('01953 452123')
        // No LOCATION: the source's default venue counts as confirmed (D-22).
        ->and($draft->venue_id)->toBe($venue->id)
        ->and($draft->start_date->toDateString())->toBe('2026-10-24')
        ->and($draft->start_time)->toBe('10:00:00')
        ->and($draft->source_url)->toBe(RECORDER_FEED_URL);
});

it('picks the category from the source default first, then the ICS CATEGORIES mappings', function () {
    $music = Category::query()->where('slug', 'music')->firstOrFail();
    $community = Category::query()->where('slug', 'community')->firstOrFail();
    CategoryMapping::query()->create(['source_value' => 'Concerts', 'category_id' => $music->id]);
    $feed = recorderCalendar([[
        'UID:cat-1@synthetic.invalid',
        'SUMMARY:Autumn Evening',
        'DTSTART:20261024T190000',
        'CATEGORIES:Concerts',
    ]]);
    recorderServe(recorderIcs($feed));

    recorderRun($this->source);
    $mapped = EventDraft::query()->sole();

    expect($mapped->category_id)->toBe($music->id)
        ->and($mapped->category_reason)->toBe("Mapped from 'Concerts'");

    $withDefault = Source::factory()->ics()->active()->create([
        'url' => 'https://other.example.org/calendar.ics',
        'default_category_id' => $community->id,
    ]);
    recorderRun($withDefault);
    $preferred = EventDraft::query()->where('source_id', $withDefault->id)->sole();

    expect($preferred->category_id)->toBe($community->id)
        ->and($preferred->category_reason)->toBe('Source default');
});

it('counts items without facts as parse errors and drafts D-19 shapes with their parse errors', function () {
    recorderServe(fn (Request $request) => recorderIcs(icsFixture(
        parse_url($request->url(), PHP_URL_HOST) === 'series.example.org' ? 'series.ics' : 'edge-cases.ics',
    )));
    $edge = recorderRun($this->source);

    expect($edge->skipped_parse_error)->toBeGreaterThan(0)
        ->and(EventDraft::query()->where('title', 'Mystery Zone Quiz')->exists())->toBeFalse();

    $series = Source::factory()->ics()->active()->create(['url' => 'https://series.example.org/calendar.ics']);
    $run = recorderRun($series);
    $moved = EventDraft::query()->where('source_id', $series->id)->where('title', 'Choir Practice')->sole();
    $exdate = EventDraft::query()->where('source_id', $series->id)->where('title', 'Wednesday Folk Club')->sole();

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($moved->parse_errors)->not->toBeEmpty()
        ->and(app(DraftPublishability::class)->bulkBlockerKeys($moved))->toContain('parse_errors')
        ->and($exdate->parse_errors)->toBe([])
        ->and($exdate->excluded_dates)->toBe(['2026-10-21'])
        ->and($exdate->recurrence_rule)->not->toBeNull();
});

it('succeeds with every counter at 0 for a calendar without events', function () {
    recorderServe(recorderIcs(recorderCalendar([])));

    $run = recorderRun($this->source);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->observed_until)->toBeNull();

    foreach (SourceRun::COUNTERS as $counter) {
        expect($run->{$counter})->toBe(0, $counter);
    }
});

it('marks a known item seen even when a new title rule now skips it', function () {
    recorderServe(recorderIcs(icsFixture('series.ics')));
    $first = recorderRun($this->source);
    $item = SourceItem::query()->where('external_id', 's-moved@synthetic.invalid')->sole();

    $this->source->forceFill(['title_rules' => [['mode' => TitleRules::MODE_SKIP, 'keyword' => 'choir']]])->save();
    $item->forceFill(['missing_runs' => 1])->save();
    $this->travel(30)->minutes();
    $second = recorderRun($this->source->refresh());

    expect($first->status)->toBe(RunStatus::Succeeded)
        ->and($second->skipped_rule)->toBe(1)
        ->and($item->refresh()->last_seen_run_id)->toBe($second->id)
        ->and($item->missing_runs)->toBe(0);
});

it('counts a throwing item as a parse error without failing the run', function () {
    // A database error on one item's draft insert (rolled back with the test).
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION recorder_test_refuse() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'refused SECRET %', NEW.title;
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER recorder_test_refuse BEFORE INSERT ON event_drafts
            FOR EACH ROW WHEN (NEW.title = 'Choir Practice') EXECUTE FUNCTION recorder_test_refuse();
        SQL);
    recorderServe(recorderIcs(icsFixture('series.ics')));
    Log::spy();

    $run = recorderRun($this->source);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->skipped_parse_error)->toBe(1)
        ->and($run->items_new)->toBeGreaterThan(0)
        ->and(EventDraft::query()->where('title', 'Choir Practice')->exists())->toBeFalse()
        // The failed item's transaction rolled back: no orphan source item.
        ->and(SourceItem::query()->where('external_id', 's-moved@synthetic.invalid')->exists())->toBeFalse();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'ingest: item failed'
        && ! str_contains(json_encode($context), 'SECRET')
        && ! str_contains(json_encode($context), 'Choir'));
});

it('fits long feed text into the draft columns instead of losing the item', function () {
    recorderServe(recorderIcs(recorderCalendar([[
        'UID:long-1@synthetic.invalid',
        'SUMMARY:'.str_repeat('Very long title ', 30),
        'DTSTART:20261024T100000',
        'LOCATION:'.str_repeat('Long venue name ', 15).'\\, Station Road\\, Attleborough',
    ]])));

    $run = recorderRun($this->source);
    $draft = EventDraft::query()->sole();

    expect($run->drafts_created)->toBe(1)
        ->and(mb_strlen($draft->title))->toBeLessThanOrEqual(255)
        ->and($draft->title)->toEndWith('…')
        ->and(mb_strlen((string) $draft->venue_name))->toBeLessThanOrEqual(120);
});

it('cuts error messages to 500 characters on a character boundary', function () {
    $message = IngestRun::errorMessage(str_repeat('é', 499).'😀😀');

    expect(mb_strlen($message))->toBe(500)
        ->and(mb_check_encoding($message, 'UTF-8'))->toBeTrue()
        ->and($message)->toEndWith('é😀')
        ->and(IngestRun::errorMessage('   '))->toBeNull();
});
