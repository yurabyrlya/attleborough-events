<?php

use App\Ingestion\Http\FetchRequest;
use App\Ingestion\Http\FetchResult;
use App\Ingestion\Http\HttpFetcher;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * robots.txt on every URL (SRC-05) and Crawl-delay on top of the 5-second
 * host minimum (D-02), through HttpFetcher. Offline: Http::fake, the Pest
 * fake HostResolver and Sleep::fake (no real waiting).
 */

const ROBOTS_BAPTIST_ROBOTS_URL = 'https://www.attleborough.church/robots.txt';

const ROBOTS_BAPTIST_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

/**
 * The Baptist Church robots.txt as captured (manifest.json robots_rules).
 */
function robotsBaptistBody(): string
{
    return "User-agent: *\nCrawl-delay: 10\nAllow: /Admin/Resources/Folders/Calendar.ics\nDisallow: /Admin/\nDisallow: /User/\n";
}

function robotsBaptistIcs(): string
{
    return sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics');
}

function robotsFetch(string $url): FetchResult
{
    return app(HttpFetcher::class)->fetch(new FetchRequest($url));
}

/**
 * @return object{robots_status: ?string, crawl_delay_seconds: ?int, robots_body: ?string, robots_fetched_at: ?string}|null
 */
function robotsHostRow(string $host = 'www.attleborough.church'): ?object
{
    return DB::table('crawl_hosts')->where('host', $host)->first();
}

/**
 * @return list<string>
 */
function robotsSentUrls(): array
{
    return Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->values()->all();
}

beforeEach(function () {
    Sleep::fake();
    freezeLondon('2026-10-18 06:00:00');
});

it('reads robots.txt first, caches it and spaces the feed request by its 10-second Crawl-delay', function () {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response(robotsBaptistBody(), 200, ['Content-Type' => 'text/plain']),
        ROBOTS_BAPTIST_ICS_URL => Http::response(robotsBaptistIcs(), 200, ['Content-Type' => 'text/calendar']),
    ]);

    $result = robotsFetch(ROBOTS_BAPTIST_ICS_URL);

    expect($result->isOk())->toBeTrue()
        ->and(robotsSentUrls())->toBe([ROBOTS_BAPTIST_ROBOTS_URL, ROBOTS_BAPTIST_ICS_URL]);

    // robots.txt goes out with the same honest User-Agent.
    Http::assertSent(fn (Request $request): bool => $request->url() === ROBOTS_BAPTIST_ROBOTS_URL
        && str_starts_with($request->header('User-Agent')[0] ?? '', 'AttleboroughEventsBot/1.0 (+'));

    $row = robotsHostRow();

    expect($row->robots_status)->toBe('rules')
        ->and($row->crawl_delay_seconds)->toBe(10)
        ->and($row->robots_body)->toBe(robotsBaptistBody());

    // No wait before robots.txt (first request to the host), then 10 s.
    Sleep::assertSequence([Sleep::for(10)->seconds()]);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalSeconds === 10);
});

it('does not request robots.txt again within 24 hours', function () {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response(robotsBaptistBody()),
        ROBOTS_BAPTIST_ICS_URL => Http::response(robotsBaptistIcs()),
    ]);

    robotsFetch(ROBOTS_BAPTIST_ICS_URL);
    $this->travel(23)->hours();
    $second = robotsFetch(ROBOTS_BAPTIST_ICS_URL);

    expect($second->isOk())->toBeTrue()
        ->and(robotsSentUrls())->toBe([ROBOTS_BAPTIST_ROBOTS_URL, ROBOTS_BAPTIST_ICS_URL, ROBOTS_BAPTIST_ICS_URL]);
});

it('returns blocked for a disallowed URL and never requests it', function () {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response(robotsBaptistBody()),
        '*' => Http::response(robotsBaptistIcs()),
    ]);

    $result = robotsFetch('https://www.attleborough.church/Admin/secret');

    expect($result->isBlocked())->toBeTrue()
        ->and($result->outcome)->toBe(FetchResult::BLOCKED)
        ->and($result->finalUrl)->toBe('https://www.attleborough.church/Admin/secret')
        ->and($result->message)->toBe('robots.txt disallows this URL');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://www.attleborough.church/Admin/secret');
    expect(robotsSentUrls())->toBe([ROBOTS_BAPTIST_ROBOTS_URL]);
});

it('allows the explicitly allowed calendar under /Admin/ because the longer Allow wins', function () {
    $url = 'https://www.attleborough.church/Admin/Resources/Folders/Calendar.ics';

    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response(robotsBaptistBody()),
        $url => Http::response(robotsBaptistIcs()),
    ]);

    expect(robotsFetch($url)->isOk())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === $url);
});

/*
|--------------------------------------------------------------------------
| Failure modes (Task 2)
|--------------------------------------------------------------------------
*/

it('maps the robots.txt response to a cached status', function (Closure $robots, string $status, bool $fetched) {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => $robots(),
        ROBOTS_BAPTIST_ICS_URL => Http::response(robotsBaptistIcs()),
    ]);

    $result = robotsFetch(ROBOTS_BAPTIST_ICS_URL);

    expect(robotsHostRow()->robots_status)->toBe($status)
        ->and(robotsHostRow()->robots_body)->toBeNull()
        ->and($result->isOk())->toBe($fetched)
        ->and($result->isBlocked())->toBe(! $fetched);

    if (! $fetched) {
        Http::assertNotSent(fn (Request $request): bool => $request->url() === ROBOTS_BAPTIST_ICS_URL);
    }
})->with([
    '404 allows everything' => [fn () => Http::response('Not found', 404), 'allow_all', true],
    '410 allows everything' => [fn () => Http::response('', 410), 'allow_all', true],
    '401 is blocked' => [fn () => Http::response('', 401), 'blocked', false],
    '403 is blocked' => [fn () => Http::response('<html>Forbidden</html>', 403), 'blocked', false],
    '429 is blocked' => [fn () => Http::response('', 429, ['Retry-After' => '60']), 'blocked', false],
    '200 with cf-mitigated is blocked' => [fn () => Http::response('User-agent: *', 200, ['cf-mitigated' => 'challenge']), 'blocked', false],
    '"Just a moment" page is blocked' => [fn () => Http::response('<!DOCTYPE html><title>Just a moment...</title>', 200), 'blocked', false],
    'challenge-platform script is blocked' => [fn () => Http::response('<script src="/cdn-cgi/challenge-platform/h/b/orchestrate"></script>', 503), 'blocked', false],
    '500 disallows everything' => [fn () => Http::response('', 500), 'disallow_all', false],
    '503 disallows everything' => [fn () => Http::response('', 503), 'disallow_all', false],
    'network failure disallows everything' => [fn () => Http::failedConnection('Connection refused'), 'disallow_all', false],
]);

it('allows every path when robots.txt is empty', function () {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response(''),
        '*' => Http::response(robotsBaptistIcs()),
    ]);

    expect(robotsFetch('https://www.attleborough.church/Admin/anything.ics')->isOk())->toBeTrue()
        ->and(robotsHostRow()->robots_status)->toBe('rules')
        ->and(robotsHostRow()->crawl_delay_seconds)->toBeNull();
});

it('follows robots.txt redirects, re-checking each hop, and uses the final file', function () {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response('', 301, ['Location' => 'https://cdn.example.org/robots/church.txt']),
        'https://cdn.example.org/robots/church.txt' => Http::response("User-agent: *\nDisallow: /Calendar/\n"),
        '*' => Http::response(robotsBaptistIcs()),
    ]);

    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isBlocked())->toBeTrue()
        ->and(robotsSentUrls())->toBe([ROBOTS_BAPTIST_ROBOTS_URL, 'https://cdn.example.org/robots/church.txt'])
        // Cached against the host that was asked about.
        ->and(robotsHostRow()->robots_status)->toBe('rules');
});

it('treats a robots.txt redirect to a private address as unreachable', function () {
    ingestFakeResolver(['intranet.example' => ['10.1.2.3']]);

    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response('', 302, ['Location' => 'https://intranet.example/robots.txt']),
        '*' => Http::response(robotsBaptistIcs()),
    ]);

    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isBlocked())->toBeTrue()
        ->and(robotsHostRow()->robots_status)->toBe('disallow_all')
        ->and(robotsSentUrls())->toBe([ROBOTS_BAPTIST_ROBOTS_URL]);
});

it('gives up after five robots.txt redirects and disallows everything', function () {
    config()->set('ingest.min_host_delay', 0);

    Http::fake(fn (Request $request) => Http::response('', 302, ['Location' => $request->url().'x']));

    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isBlocked())->toBeTrue()
        ->and(robotsHostRow()->robots_status)->toBe('disallow_all');

    Http::assertSentCount(6);
});

it('refreshes a robots.txt cache older than 24 hours', function () {
    Http::fakeSequence(ROBOTS_BAPTIST_ROBOTS_URL)
        ->push("User-agent: *\nDisallow: /Calendar/\n")
        ->push("User-agent: *\nDisallow: /Admin/\n");
    Http::fake([ROBOTS_BAPTIST_ICS_URL => Http::response(robotsBaptistIcs())]);

    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isBlocked())->toBeTrue();

    $this->travel(24)->hours();
    $this->travel(1)->minutes();

    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isOk())->toBeTrue()
        ->and(robotsHostRow()->robots_body)->toBe("User-agent: *\nDisallow: /Admin/\n")
        ->and(robotsSentUrls())->toBe([ROBOTS_BAPTIST_ROBOTS_URL, ROBOTS_BAPTIST_ROBOTS_URL, ROBOTS_BAPTIST_ICS_URL]);
});

it('retries an unreachable robots.txt after an hour rather than a day', function () {
    Http::fakeSequence(ROBOTS_BAPTIST_ROBOTS_URL)
        ->push('', 500)
        ->push('', 404);
    Http::fake([ROBOTS_BAPTIST_ICS_URL => Http::response(robotsBaptistIcs())]);

    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isBlocked())->toBeTrue();

    $this->travel(30)->minutes();
    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isBlocked())->toBeTrue();

    $this->travel(31)->minutes();
    expect(robotsFetch(ROBOTS_BAPTIST_ICS_URL)->isOk())->toBeTrue()
        ->and(robotsHostRow()->robots_status)->toBe('allow_all');
});

it('checks robots.txt of the redirect target host before following a redirect', function () {
    Http::fake([
        ROBOTS_BAPTIST_ROBOTS_URL => Http::response(''),
        ROBOTS_BAPTIST_ICS_URL => Http::response('', 302, ['Location' => 'https://calendars.example.net/private/church.ics']),
        'https://calendars.example.net/robots.txt' => Http::response("User-agent: AttleboroughEventsBot\nDisallow: /private/\n"),
        '*' => Http::response(robotsBaptistIcs()),
    ]);

    $result = robotsFetch(ROBOTS_BAPTIST_ICS_URL);

    expect($result->isBlocked())->toBeTrue()
        ->and($result->finalUrl)->toBe('https://calendars.example.net/private/church.ics');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://calendars.example.net/private/church.ics');
});

it('keeps the robots.txt decision when the fetch deadline is already spent', function () {
    config()->set('ingest.fetch_deadline', 0);
    Http::fake();

    $result = robotsFetch(ROBOTS_BAPTIST_ICS_URL);

    expect($result->isFailed())->toBeTrue()
        ->and(robotsHostRow())->toBeNull();

    Http::assertNothingSent();
});
