<?php

use App\Ingestion\Http\FetchRequest;
use App\Ingestion\Http\HostBusy;
use App\Ingestion\Http\HostThrottle;
use App\Ingestion\Http\HttpFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Per-host request spacing across workers (SRC-05: at least 5 s per host;
 * D-02: robots Crawl-delay on top). Slots live in crawl_hosts under a row
 * lock, so separate HostThrottle instances (separate workers) share them.
 */

beforeEach(function () {
    Sleep::fake();
    freezeLondon('2026-10-18 06:00:00');
});

function throttleRow(string $host): object
{
    return DB::table('crawl_hosts')->where('host', $host)->first();
}

it('spaces reservations for one host by the delay', function () {
    expect((new HostThrottle)->reserve('www.attleborough.church', 5, 20))->toBe(0)
        ->and((new HostThrottle)->reserve('www.attleborough.church', 5, 20))->toBe(5)
        ->and((new HostThrottle)->reserve('www.attleborough.church', 5, 20))->toBe(10);

    $row = throttleRow('www.attleborough.church');

    expect(DB::selectOne("select to_char(last_request_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as last, to_char(next_allowed_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as next from crawl_hosts where host = ?", ['www.attleborough.church']))
        ->last->toBe('2026-10-18 05:00:10')
        ->next->toBe('2026-10-18 05:00:15')
        ->and($row)->not->toBeNull();
});

it('keeps hosts independent', function () {
    $throttle = new HostThrottle;

    expect($throttle->reserve('a.example', 5, 20))->toBe(0)
        ->and($throttle->reserve('b.example', 5, 20))->toBe(0)
        ->and($throttle->reserve('a.example', 5, 20))->toBe(5)
        ->and($throttle->reserve('b.example', 5, 20))->toBe(5);
});

it('frees the slot once the delay has passed', function () {
    $throttle = new HostThrottle;

    $throttle->reserve('a.example', 5, 20);
    $this->travel(6)->seconds();

    expect($throttle->reserve('a.example', 5, 20))->toBe(0);
});

it('never undercuts the host\'s stored Crawl-delay', function () {
    $throttle = new HostThrottle;

    $throttle->reserve('a.example', 5, 20);
    DB::table('crawl_hosts')->where('host', 'a.example')->update(['crawl_delay_seconds' => 10]);

    expect($throttle->reserve('a.example', 5, 20))->toBe(10);
});

it('refuses a wait longer than allowed without booking the slot', function () {
    $throttle = new HostThrottle;

    $throttle->reserve('a.example', 30, 20);

    expect(fn () => $throttle->reserve('a.example', 30, 20))
        ->toThrow(fn (HostBusy $e) => expect($e->seconds)->toBe(30)->and($e->host)->toBe('a.example'));

    // The refused call booked nothing: the next slot is still 30 s away, not 60.
    expect(fn () => $throttle->reserve('a.example', 30, 20))
        ->toThrow(fn (HostBusy $e) => expect($e->seconds)->toBe(30));

    $this->travel(30)->seconds();
    expect($throttle->reserve('a.example', 30, 20))->toBe(0);
});

it('locks the host row while reserving', function () {
    DB::enableQueryLog();

    (new HostThrottle)->reserve('a.example', 5, 20);

    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->toContain('for update');
});

it('keys hosts in lower case with the port only when it is not the default', function (string $url, string $key) {
    expect(HostThrottle::keyFor($url))->toBe($key);
})->with([
    ['https://WWW.Attleborough.Church/Calendar.ics', 'www.attleborough.church'],
    ['https://example.com:443/a', 'example.com'],
    ['http://example.com:80/a', 'example.com'],
    ['https://example.com:80/a', 'example.com:80'],
    ['http://example.com:443/a', 'example.com:443'],
]);

it('sleeps through the 5-second minimum between two fetches of one host', function () {
    DB::table('crawl_hosts')->insert([
        'host' => 'www.attleborough.church',
        'next_allowed_at' => '2000-01-01 00:00:00',
        'robots_status' => 'allow_all',
        'robots_fetched_at' => now()->utc()->format('Y-m-d H:i:s'),
    ]);
    Http::fake(['*' => Http::response(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'))]);

    $fetcher = app(HttpFetcher::class);
    $fetcher->fetch(new FetchRequest('https://www.attleborough.church/Calendar/Calendar.ics'));
    $fetcher->fetch(new FetchRequest('https://www.attleborough.church/Calendar/Calendar.ics'));

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('throws HostBusy for a 12000-second Crawl-delay instead of sleeping', function () {
    // Attleborough Primary's robots.txt (STATE.md, RESEARCH Pitfall 7).
    Http::fake([
        'https://www.attleboroughprimary.co.uk/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 12000\n"),
        '*' => Http::response(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics')),
    ]);

    expect(fn () => app(HttpFetcher::class)->fetch(new FetchRequest('https://www.attleboroughprimary.co.uk/calendar.ics')))
        ->toThrow(fn (HostBusy $e) => expect($e->seconds)->toBe(12000)
            ->and($e->host)->toBe('www.attleboroughprimary.co.uk'));

    Sleep::assertNeverSlept();
    Http::assertSentCount(1);

    expect(throttleRow('www.attleboroughprimary.co.uk')->crawl_delay_seconds)->toBe(12000);
});

it('fetches the 12000-second host once its delay has passed', function () {
    Http::fake([
        'https://www.attleboroughprimary.co.uk/robots.txt' => Http::response("User-agent: *\nCrawl-delay: 12000\n"),
        '*' => Http::response(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics')),
    ]);

    $fetch = fn () => app(HttpFetcher::class)->fetch(new FetchRequest('https://www.attleboroughprimary.co.uk/calendar.ics'));

    expect($fetch)->toThrow(HostBusy::class);

    $this->travel(12000)->seconds();

    expect($fetch()->isOk())->toBeTrue();
    Sleep::assertNeverSlept();
});

it('throws HostBusy when a wait would leave less than one request timeout before the deadline', function () {
    DB::table('crawl_hosts')->insert([
        'host' => 'www.attleborough.church',
        'next_allowed_at' => '2000-01-01 00:00:00',
        'robots_status' => 'allow_all',
        'robots_fetched_at' => now()->utc()->format('Y-m-d H:i:s'),
    ]);
    Http::fake(['*' => Http::response(sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics'))]);

    (new HostThrottle)->reserve('www.attleborough.church', 5, 20);

    // 25 s of the 55-second deadline are gone: a 5-second wait would leave
    // 25 s, less than the 30-second request timeout.
    expect(fn () => app(HttpFetcher::class)->fetch(new FetchRequest(
        'https://www.attleborough.church/Calendar/Calendar.ics',
        startedAt: now()->toImmutable()->subSeconds(25),
    )))->toThrow(fn (HostBusy $e) => expect($e->seconds)->toBe(5));

    Http::assertNothingSent();
    Sleep::assertNeverSlept();
});
