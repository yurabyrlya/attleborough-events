<?php

use App\Enums\FetchFailureKind;
use App\Ingestion\Http\FetchRequest;
use App\Ingestion\Http\FetchResult;
use App\Ingestion\Http\HttpFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * The single polite fetch path (SRC-05, SRC-06). Offline: Http::fake for the
 * feed, the Pest fake HostResolver for DNS (tests/Pest.php).
 *
 * Every fetch now reads robots.txt and reserves a host slot first (03-03,
 * covered in RobotsPolicyTest and HostThrottleTest). Here each host's
 * robots.txt is already cached as allow-all, so the request counts below
 * are the feed requests only, and Sleep is faked (moving the clock with
 * each wait) so host spacing costs no real time.
 */

const FETCHER_BAPTIST_ICS_URL = 'https://www.attleborough.church/Calendar/Calendar.ics';

function fetcherBaptistIcs(): string
{
    return sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics');
}

function fetcherFetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResult
{
    return app(HttpFetcher::class)->fetch(new FetchRequest($url, $etag, $lastModified));
}

/**
 * Cache an allow-all robots.txt for each host, as a 404 robots.txt leaves it.
 * No earlier request is recorded, so tests that freeze the clock in the
 * past still get the first request without a wait.
 */
function fetcherAllowAllRobots(string ...$hosts): void
{
    $now = now()->utc()->format('Y-m-d H:i:s');

    foreach ($hosts as $host) {
        DB::table('crawl_hosts')->insert([
            'host' => $host,
            'next_allowed_at' => '2000-01-01 00:00:00',
            'robots_status' => 'allow_all',
            'robots_fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    fetcherAllowAllRobots('www.attleborough.church', 'example.com', 'intranet.example');
});

it('fetches the Baptist Church ICS feed with the bot User-Agent and returns an ok result', function () {
    config()->set('ingest.contact_url', 'https://whatsonattleborough.co.uk/about-crawler');

    Http::fake([
        FETCHER_BAPTIST_ICS_URL => Http::response(fetcherBaptistIcs(), 200, ['Content-Type' => 'text/calendar; charset=utf-8']),
    ]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->outcome)->toBe(FetchResult::OK)
        ->and($result->isOk())->toBeTrue()
        ->and($result->failureKind)->toBeNull()
        ->and($result->body)->toBe(fetcherBaptistIcs())
        ->and($result->bytes)->toBe(strlen(fetcherBaptistIcs()))
        ->and($result->httpStatus)->toBe(200)
        ->and($result->finalUrl)->toBe(FETCHER_BAPTIST_ICS_URL)
        ->and($result->headers['content-type'])->toBe('text/calendar; charset=utf-8');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === FETCHER_BAPTIST_ICS_URL
        && $request->method() === 'GET'
        && $request->header('User-Agent') === ['AttleboroughEventsBot/1.0 (+https://whatsonattleborough.co.uk/about-crawler)']
        && str_contains($request->header('Accept')[0] ?? '', 'text/calendar'));
});

it('records a bot challenge page as content_invalid and keeps its body for storage', function () {
    $challenge = '<!DOCTYPE html><title>Just a moment...</title>';

    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response($challenge, 200, ['Content-Type' => 'text/html'])]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureKind)->toBe(FetchFailureKind::ContentInvalid)
        ->and($result->body)->toBe($challenge)
        ->and($result->httpStatus)->toBe(200)
        ->and($result->message)->not->toContain('Just a moment');

    // Recorded, never worked around: one request, no retry with another agent.
    Http::assertSentCount(1);
});

it('accepts the feed behind a UTF-8 BOM and blank lines whatever the Content-Type', function () {
    $body = "\xEF\xBB\xBF\r\n\r\n".fetcherBaptistIcs();

    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response($body, 200, ['Content-Type' => 'text/calendar;;charset=UTF-8'])]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->isOk())->toBeTrue()
        ->and($result->body)->toBe($body);
});

it('refuses a host that resolves to a private address without sending anything', function () {
    ingestFakeResolver(['www.attleborough.church' => ['10.0.0.5']]);
    Http::fake();

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureKind)->toBe(FetchFailureKind::SsrfBlocked);

    Http::assertNothingSent();
});

it('fails closed when the host does not resolve', function () {
    ingestFakeResolver(['www.attleborough.church' => []]);
    Http::fake();

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->isFailed())->toBeTrue()
        ->and($result->failureKind)->toBe(FetchFailureKind::Network)
        ->and($result->message)->toBe('Host could not be resolved');

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Policy edges (Task 2)
|--------------------------------------------------------------------------
*/

/**
 * A minimal valid calendar padded with X- lines to exactly $bytes bytes.
 */
function fetcherIcsOfBytes(int $bytes): string
{
    $head = "BEGIN:VCALENDAR\r\n";
    $tail = "END:VCALENDAR\r\n";

    return $head.str_repeat('X', $bytes - strlen($head) - strlen($tail)).$tail;
}

it('refuses a host where any one resolved address is private', function () {
    ingestFakeResolver(['www.attleborough.church' => ['93.184.215.14', '10.0.0.5']]);
    Http::fake();

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::SsrfBlocked);
    Http::assertNothingSent();
});

it('refuses a URL outside the policy before resolving or sending', function (string $url) {
    Http::fake();

    expect(fetcherFetch($url)->failureKind)->toBe(FetchFailureKind::SsrfBlocked);
    Http::assertNothingSent();
})->with([
    'port 8080' => 'https://www.attleborough.church:8080/Calendar/Calendar.ics',
    'userinfo' => 'https://admin:secret@www.attleborough.church/Calendar/Calendar.ics',
    'file' => 'file:///etc/passwd',
    'metadata IP literal' => 'http://169.254.169.254/latest/meta-data',
]);

it('re-checks every redirect hop and blocks a redirect to the metadata address', function () {
    Http::fake([
        FETCHER_BAPTIST_ICS_URL => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest']),
        '*' => Http::response(fetcherBaptistIcs()),
    ]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::SsrfBlocked)
        ->and($result->finalUrl)->toBe('http://169.254.169.254/latest');

    Http::assertSentCount(1);
});

it('blocks a redirect to a host that resolves privately', function () {
    ingestFakeResolver(['intranet.example' => ['192.168.1.10']]);

    Http::fake([
        FETCHER_BAPTIST_ICS_URL => Http::response('', 302, ['Location' => 'https://intranet.example/feed.ics']),
        '*' => Http::response(fetcherBaptistIcs()),
    ]);

    expect(fetcherFetch(FETCHER_BAPTIST_ICS_URL)->failureKind)->toBe(FetchFailureKind::SsrfBlocked);
    Http::assertSentCount(1);
});

it('follows five redirects and fails on the sixth', function () {
    // Same-host hops are spaced too (HostThrottleTest); here only the count matters.
    config()->set('ingest.min_host_delay', 0);

    $hops = fn (int $count): array => collect(range(0, $count - 1))
        ->mapWithKeys(fn (int $i): array => ["https://example.com/r{$i}" => Http::response('', 302, ['Location' => 'https://example.com/r'.($i + 1)])])
        ->all();

    Http::fake($hops(5) + ['https://example.com/r5' => Http::response(fetcherBaptistIcs())]);

    $ok = fetcherFetch('https://example.com/r0');

    expect($ok->isOk())->toBeTrue()
        ->and($ok->finalUrl)->toBe('https://example.com/r5');
    Http::assertSentCount(6);
});

it('fails as http_status when there are more than five redirects', function () {
    config()->set('ingest.min_host_delay', 0);

    Http::fake(fn (Request $request) => Http::response('', 302, [
        'Location' => 'https://example.com/r'.((int) substr((string) parse_url($request->url(), PHP_URL_PATH), 2) + 1),
    ]));

    $result = fetcherFetch('https://example.com/r0');

    expect($result->failureKind)->toBe(FetchFailureKind::HttpStatus)
        ->and($result->httpStatus)->toBe(302)
        ->and($result->message)->toContain('Too many redirects');

    Http::assertSentCount(6);
});

it('resolves a relative Location against the current URL', function () {
    Http::fake([
        FETCHER_BAPTIST_ICS_URL => Http::response('', 301, ['Location' => '/feeds/new.ics']),
        'https://www.attleborough.church/feeds/new.ics' => Http::response(fetcherBaptistIcs()),
    ]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->isOk())->toBeTrue()
        ->and($result->finalUrl)->toBe('https://www.attleborough.church/feeds/new.ics');
});

it('fails a redirect without a Location as http_status', function () {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response('', 302)]);

    expect(fetcherFetch(FETCHER_BAPTIST_ICS_URL)->failureKind)->toBe(FetchFailureKind::HttpStatus);
});

it('accepts a decoded body of exactly max_bytes', function () {
    config()->set('ingest.max_bytes', 1024);
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response(fetcherIcsOfBytes(1024))]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->isOk())->toBeTrue()
        ->and($result->bytes)->toBe(1024);
});

it('fails a decoded body of max_bytes + 1 as too_large', function () {
    config()->set('ingest.max_bytes', 1024);
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response(fetcherIcsOfBytes(1025))]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::TooLarge)
        ->and($result->body)->toBeNull();
});

it('counts the size in bytes, not characters', function () {
    config()->set('ingest.max_bytes', 1024);
    // 1015 characters but 1025 bytes: ten of them are two-byte "é".
    $body = substr(fetcherIcsOfBytes(1015), 0, -strlen("END:VCALENDAR\r\n") - 10).str_repeat('é', 10)."END:VCALENDAR\r\n";

    expect(mb_strlen($body))->toBeLessThan(1024)->and(strlen($body))->toBe(1025);

    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response($body)]);

    expect(fetcherFetch(FETCHER_BAPTIST_ICS_URL)->failureKind)->toBe(FetchFailureKind::TooLarge);
});

it('refuses a declared Content-Length above max_bytes without reading the body', function () {
    config()->set('ingest.max_bytes', 1024);
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response('BEGIN:VCALENDAR', 200, ['Content-Length' => '2048'])]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::TooLarge)
        ->and($result->body)->toBeNull()
        ->and($result->bytes)->toBe(0);
});

it('sends the conditional headers it is given and reports 304 as not_modified', function () {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response('', 304, ['ETag' => '"abc"'])]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL, '"abc"', 'Sat, 17 Oct 2026 18:05:08 GMT');

    expect($result->isNotModified())->toBeTrue()
        ->and($result->httpStatus)->toBe(304)
        ->and($result->body)->toBeNull()
        ->and($result->etag)->toBe('"abc"')
        ->and($result->lastModified)->toBe('Sat, 17 Oct 2026 18:05:08 GMT');

    Http::assertSent(fn (Request $request): bool => $request->header('If-None-Match') === ['"abc"']
        && $request->header('If-Modified-Since') === ['Sat, 17 Oct 2026 18:05:08 GMT']);
});

it('sends no conditional headers without validators and returns the new ones', function () {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response(fetcherBaptistIcs(), 200, [
        'ETag' => '"v2"',
        'Last-Modified' => 'Sun, 18 Oct 2026 05:30:00 GMT',
    ])]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->etag)->toBe('"v2"')
        ->and($result->lastModified)->toBe('Sun, 18 Oct 2026 05:30:00 GMT');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('If-None-Match')
        && ! $request->hasHeader('If-Modified-Since'));
});

it('reads Retry-After seconds on 429 and 503', function (int $status) {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response('slow down', $status, ['Retry-After' => '120'])]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::RateLimited)
        ->and($result->httpStatus)->toBe($status)
        ->and($result->retryAfterSeconds)->toBe(120)
        ->and($result->message)->not->toContain('slow down');
})->with([429, 503]);

it('reads Retry-After as an HTTP date', function () {
    freezeLondon('2026-10-18 10:00:00');
    $twoHours = now()->utc()->addHours(2)->format(DATE_RFC7231);

    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response('', 429, ['Retry-After' => $twoHours])]);

    expect(fetcherFetch(FETCHER_BAPTIST_ICS_URL)->retryAfterSeconds)->toBe(7200);
});

it('caps Retry-After at 24 hours and ignores an unreadable one', function () {
    Http::fake([
        'https://example.com/big' => Http::response('', 429, ['Retry-After' => '999999']),
        'https://example.com/huge' => Http::response('', 429, ['Retry-After' => '99999999999999999999']),
        'https://example.com/junk' => Http::response('', 503, ['Retry-After' => 'soon']),
        'https://example.com/none' => Http::response('', 503),
    ]);

    expect(fetcherFetch('https://example.com/big')->retryAfterSeconds)->toBe(86400)
        ->and(fetcherFetch('https://example.com/huge')->retryAfterSeconds)->toBe(86400)
        ->and(fetcherFetch('https://example.com/junk')->retryAfterSeconds)->toBeNull()
        ->and(fetcherFetch('https://example.com/none')->retryAfterSeconds)->toBeNull();
});

it('reports other error statuses as http_status without retrying', function (int $status) {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::response('<html>Forbidden</html>', $status)]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::HttpStatus)
        ->and($result->httpStatus)->toBe($status)
        ->and($result->message)->toBe('HTTP '.$status);

    // A bot wall is recorded, never worked around with another User-Agent.
    Http::assertSentCount(1);
})->with([403, 404, 500]);

it('turns a connection failure into a network result', function () {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::failedConnection('Could not resolve host')]);

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::Network)
        ->and($result->message)->toBe('Network error');
});

it('turns a timed-out connection into a timeout result', function () {
    Http::fake([FETCHER_BAPTIST_ICS_URL => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')]);

    expect(fetcherFetch(FETCHER_BAPTIST_ICS_URL)->failureKind)->toBe(FetchFailureKind::Timeout);
});

it('fails as timeout without sending when the fetch deadline is spent', function () {
    config()->set('ingest.fetch_deadline', 0);
    Http::fake();

    $result = fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect($result->failureKind)->toBe(FetchFailureKind::Timeout);
    Http::assertNothingSent();
});

it('shrinks the request timeout to the time left of the fetch deadline', function () {
    freezeLondon('2026-10-18 05:30:00');
    $options = [];

    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(fetcherBaptistIcs());
    });

    $result = app(HttpFetcher::class)->fetch(new FetchRequest(
        FETCHER_BAPTIST_ICS_URL,
        startedAt: now()->toImmutable()->subSeconds(40),
    ));

    expect($result->isOk())->toBeTrue()
        ->and($options['timeout'])->toBeLessThanOrEqual(15)
        ->and($options['timeout'])->toBeGreaterThan(0)
        ->and($options['connect_timeout'])->toBeLessThanOrEqual(10);
});

it('uses the full 10 s connect and 30 s total timeouts on a fresh fetch', function () {
    $options = [];

    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(fetcherBaptistIcs());
    });

    fetcherFetch(FETCHER_BAPTIST_ICS_URL);

    expect((float) $options['timeout'])->toBe(30.0)
        ->and((float) $options['connect_timeout'])->toBe(10.0)
        ->and($options['allow_redirects'])->toBeFalse();
});
