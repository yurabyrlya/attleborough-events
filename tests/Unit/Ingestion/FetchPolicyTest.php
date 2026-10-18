<?php

use App\Ingestion\Http\BodyValidator;
use App\Ingestion\Http\CappedSink;
use App\Ingestion\Http\DnsHostResolver;
use App\Ingestion\Http\HostResolver;
use App\Ingestion\Http\SsrfGuard;

/*
 * Fetch policy edges proven offline (SRC-05, SRC-06, T-03-03..05): the SSRF
 * address table, the URL policy, the CURLOPT_RESOLVE pin builder (curl
 * options never run under Http::fake, RESEARCH Pitfall 9), the ICS body
 * check and the decoded-byte sink.
 */

it('treats non-public addresses as not public', function (string $ip) {
    expect(app(SsrfGuard::class)->isPublic($ip))->toBeFalse();
})->with([
    'private 10/8' => '10.0.0.1',
    'loopback' => '127.0.0.1',
    'cloud metadata (link-local)' => '169.254.169.254',
    'CGNAT 100.64/10' => '100.64.0.1',
    'this network 0/8' => '0.0.0.0',
    'IETF protocol 192.0.0/24' => '192.0.0.1',
    'benchmarking 198.18/15' => '198.18.0.1',
    'IPv6 loopback' => '::1',
    'IPv6 link-local' => 'fe80::1',
    'IPv6 unique local' => 'fc00::1',
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv6 documentation' => '2001:db8::1',
    'IPv4 multicast' => '224.0.0.1',
    'NAT64 embedding 127.0.0.1' => '64:ff9b::7f00:1',
    'IPv6 multicast' => 'ff02::1',
    'not an address' => 'example.com',
]);

it('treats globally routable addresses as public', function (string $ip) {
    expect(app(SsrfGuard::class)->isPublic($ip))->toBeTrue();
})->with([
    'IPv4' => '93.184.215.14',
    'IPv6' => '2606:2800:220:1::1',
]);

it('refuses URLs outside the fetch policy', function (string $url) {
    expect(app(SsrfGuard::class)->checkUrl($url))->toBeString();
})->with([
    'ftp' => 'ftp://example.com/feed.ics',
    'file' => 'file:///etc/passwd',
    'userinfo' => 'https://user:pass@example.com/feed.ics',
    'user only' => 'https://user@example.com/feed.ics',
    'other port' => 'https://example.com:8080/feed.ics',
    'no host' => 'https:///feed.ics',
    'relative' => '/feed.ics',
]);

it('allows http and https on the default ports', function (string $url) {
    expect(app(SsrfGuard::class)->checkUrl($url))->toBeNull();
})->with([
    'https' => 'https://www.attleborough.church/Calendar/Calendar.ics',
    'http' => 'http://example.com/feed.ics',
    'explicit 443' => 'https://example.com:443/feed.ics',
    'explicit 80' => 'http://example.com:80/feed.ics',
    'upper-case scheme' => 'HTTPS://example.com/feed.ics',
]);

it('builds the CURLOPT_RESOLVE pin for the checked address and port', function () {
    $guard = app(SsrfGuard::class);

    expect($guard->resolveOptions('www.attleborough.church', 443, '93.184.215.14'))->toBe(['www.attleborough.church:443:93.184.215.14'])
        ->and($guard->resolveOptions('example.com', 80, '93.184.215.14'))->toBe(['example.com:80:93.184.215.14'])
        ->and($guard->resolveOptions('example.com', 443, '2606:2800:220:1::1'))->toBe(['example.com:443:[2606:2800:220:1::1]'])
        ->and($guard->portOf('https://example.com/a'))->toBe(443)
        ->and($guard->portOf('http://example.com/a'))->toBe(80)
        ->and($guard->portOf('https://example.com:80/a'))->toBe(80);
});

it('resolves hosts through the HostResolver and IP literals to themselves', function () {
    ingestFakeResolver(['mixed.example' => ['93.184.215.14', '10.0.0.5']]);
    $guard = app(SsrfGuard::class);

    expect($guard->addresses('mixed.example'))->toBe(['93.184.215.14', '10.0.0.5'])
        ->and($guard->addresses('169.254.169.254'))->toBe(['169.254.169.254'])
        ->and($guard->addresses('anything.example'))->toBe(['93.184.215.14'])
        ->and($guard->hostOf('https://[::1]/feed.ics'))->toBe('::1')
        ->and($guard->hostOf('https://WWW.Example.COM/feed.ics'))->toBe('www.example.com');
});

it('binds the DNS resolver in production; tests replace it with the fake', function () {
    expect(app(HostResolver::class))->not->toBeInstanceOf(DnsHostResolver::class);

    app()->forgetInstance(HostResolver::class);

    expect(app(HostResolver::class))->toBeInstanceOf(DnsHostResolver::class);
});

it('validates ICS bodies before extraction', function (string $body, bool $valid) {
    $reason = app(BodyValidator::class)->validate('ics', $body);

    expect($reason === null)->toBe($valid);
})->with([
    'BOM, blank line and indent' => ["\xEF\xBB\xBF\r\n BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", true],
    'plain calendar' => ["BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", true],
    'lower-case property name' => ["begin:vcalendar\r\nend:vcalendar\r\n", true],
    'calendar with HTML in X-ALT-DESC' => ["BEGIN:VCALENDAR\r\nX-ALT-DESC;FMTTYPE=text/html:<html><body>Hi</body></html>\r\nEND:VCALENDAR\r\n", true],
    'HTML' => ['<html>', false],
    'HTML after whitespace' => ["\n  <!DOCTYPE html><html></html>", false],
    'challenge text without markup first' => ['Just a moment... checking your browser', false],
    'vCard' => ["BEGIN:VCARD\r\nEND:VCARD\r\n", false],
    'empty' => ['', false],
    'only whitespace' => ["  \r\n", false],
]);

it('names the reason a body is not a calendar', function () {
    $validator = app(BodyValidator::class);

    expect($validator->validate('ics', '<!DOCTYPE html><title>Just a moment...</title>'))->toBe('Bot challenge page instead of a calendar')
        ->and($validator->validate('ics', '<html><body>Blocked</body></html>'))->toBe('HTML page instead of a calendar')
        ->and($validator->validate('ics', 'BEGIN:VCARD'))->toBe('Not a calendar (no BEGIN:VCALENDAR)')
        ->and($validator->validate('ics', ''))->toBe('Empty response')
        ->and($validator->validate('rss', 'BEGIN:VCALENDAR'))->toBe('Unsupported extraction method');
});

it('caps the transfer sink at exactly max bytes', function () {
    $exact = new CappedSink(1024);
    $over = new CappedSink(1024);

    expect(fwrite($exact->stream, str_repeat('a', 1024)))->toBe(1024)
        ->and($exact->overflowed())->toBeFalse()
        ->and(fwrite($over->stream, str_repeat('a', 1000)))->toBe(1000)
        ->and(fwrite($over->stream, str_repeat('a', 25)))->toBeFalse()
        ->and($over->overflowed())->toBeTrue();

    rewind($over->stream);
    expect(strlen((string) stream_get_contents($over->stream)))->toBe(1000);

    $exact->close();
    $over->close();
});
