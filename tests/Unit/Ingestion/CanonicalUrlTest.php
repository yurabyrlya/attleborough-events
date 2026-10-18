<?php

use App\Ingestion\CanonicalUrl;

/*
 * PIPE-05 (03-11): the comparable form of an event page URL. Two links to
 * the same page canonicalise to the same string; non-http(s) never does.
 */

it('lower-cases scheme and host, strips www, the default port, tracking parameters, the fragment and a trailing slash, and sorts the query', function () {
    expect(CanonicalUrl::of('HTTPS://WWW.Example.org:443/Events/?utm_source=x&b=2&a=1#top'))
        ->toBe('https://example.org/Events?a=1&b=2');
});

it('removes fbclid, gclid and mc_* parameters', function (string $param) {
    expect(CanonicalUrl::of("https://example.org/event/fair?{$param}=abc123&id=7"))
        ->toBe('https://example.org/event/fair?id=7');
})->with(['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'UTM_Medium']);

it('keeps the root path as a single slash', function (string $url) {
    expect(CanonicalUrl::of($url))->toBe('https://example.org/');
})->with(['https://example.org/', 'https://example.org', 'https://www.example.org/#events', 'https://example.org/?utm_campaign=x']);

it('keeps the path case and a non-default port', function () {
    expect(CanonicalUrl::of('http://Example.org:8080/Events/Fair/'))->toBe('http://example.org:8080/Events/Fair')
        ->and(CanonicalUrl::of('http://example.org:80/a'))->toBe('http://example.org/a');
});

it('drops user info', function () {
    expect(CanonicalUrl::of('https://user:secret@example.org/a'))->toBe('https://example.org/a');
});

it('gives the same form for two spellings of one page', function () {
    expect(CanonicalUrl::of('https://attleboroughtc.org.uk/event/name-removed-yoga/2026-10-25/?utm_source=newsletter'))
        ->toBe(CanonicalUrl::of('https://www.AttleboroughTC.org.uk/event/name-removed-yoga/2026-10-25#details'));
});

it('returns null for anything that is not an http(s) URL with a host', function (?string $url) {
    expect(CanonicalUrl::of($url))->toBeNull();
})->with([
    'javascript' => ['javascript:alert(1)'],
    'mailto' => ['mailto:clerk@example.org'],
    'ftp' => ['ftp://example.org/file'],
    'relative' => ['/events/fair'],
    'blank' => ['   '],
    'null' => [null],
    'no host' => ['https:///path'],
]);
