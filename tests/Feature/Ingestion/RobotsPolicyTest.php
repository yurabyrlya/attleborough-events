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
