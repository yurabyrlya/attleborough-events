<?php

use App\Enums\FetchFailureKind;
use App\Ingestion\Http\FetchRequest;
use App\Ingestion\Http\FetchResult;
use App\Ingestion\Http\HttpFetcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The single polite fetch path (SRC-05, SRC-06). Offline: Http::fake for the
 * feed, the Pest fake HostResolver for DNS (tests/Pest.php).
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
