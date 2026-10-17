<?php

use Dom\HTMLDocument;

use function Pest\Laravel\get;

/*
 * CAL-01 privacy prohibition (T-01-37): public pages load nothing from a
 * third-party host. The real guarantee is the allow-list (relative paths or
 * the host of config('app.url')); the deny-list only catches known CDNs.
 */

const THIRD_PARTY_DENY_LIST = '#fonts\.bunny\.net|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit\.net|cdn\.jsdelivr\.net|unpkg\.com|cdnjs\.cloudflare\.com|cdn\.tailwindcss\.com#i';

/**
 * Every src/href on link, script, img, source and iframe elements.
 *
 * @return list<string>
 */
function resourceUrlsIn(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $urls = [];

    foreach ($document->querySelectorAll('link, script, img, source, iframe') as $element) {
        foreach (['src', 'href', 'srcset'] as $attribute) {
            if ($element->hasAttribute($attribute)) {
                $urls[] = (string) $element->getAttribute($attribute);
            }
        }
    }

    return $urls;
}

it('renders exactly one same-origin Vite stylesheet and no script when Vite is enabled', function () {
    $this->withVite();
    app()->usePublicPath(base_path('tests/Fixtures/public'));

    $html = (string) get('/')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $stylesheets = $document->querySelectorAll('link[rel="stylesheet"]');

    expect($stylesheets->length)->toBe(1)
        ->and((string) $stylesheets->item(0)->getAttribute('href'))->toEndWith('/build/assets/app-fixture.css')
        ->and($document->querySelectorAll('script')->length)->toBe(0)
        ->and($document->querySelectorAll('link[rel="modulepreload"]')->length)->toBe(0);
});

it('references resources only by relative path or on the app host', function () {
    $this->withVite();
    app()->usePublicPath(base_path('tests/Fixtures/public'));

    $html = (string) get('/')->assertOk()->getContent();
    $appUrl = parse_url((string) config('app.url'));
    $ownOrigin = ($appUrl['scheme'] ?? 'http').'://'.($appUrl['host'] ?? 'localhost')
        .(isset($appUrl['port']) ? ':'.$appUrl['port'] : '');
    $urls = resourceUrlsIn($html);

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $url) {
        $relative = str_starts_with($url, '/') && ! str_starts_with($url, '//');
        $ownHost = $url === $ownOrigin || str_starts_with($url, $ownOrigin.'/');

        expect($relative || $ownHost)->toBeTrue("Third-party resource URL: {$url}");
    }
});

it('contains no known CDN host and no preconnect or dns-prefetch hints', function () {
    $html = (string) get('/')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect(preg_match(THIRD_PARTY_DENY_LIST, $html))->toBe(0)
        ->and($document->querySelectorAll('link[rel~="preconnect"], link[rel~="dns-prefetch"]')->length)->toBe(0);
});

it('loads only the CSS entry from the layout and has no skeleton JS or welcome view', function () {
    $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($layout)->toContain("@vite('resources/css/app.css')")
        ->and($layout)->not->toContain('resources/js/app.js')
        ->and(file_exists(base_path('resources/js/app.js')))->toBeFalse()
        ->and(file_exists(base_path('resources/js/bootstrap.js')))->toBeFalse()
        ->and(file_exists(base_path('resources/views/welcome.blade.php')))->toBeFalse();
});

/*
 * D-17 (SUB-04): Cloudflare Turnstile on /submit is the single third-party
 * exception. Every other public page still loads nothing third-party.
 */

const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

/**
 * Resource URLs that are neither relative nor on the app host.
 *
 * @return list<string>
 */
function thirdPartyUrlsIn(string $html): array
{
    $appUrl = parse_url((string) config('app.url'));
    $ownOrigin = ($appUrl['scheme'] ?? 'http').'://'.($appUrl['host'] ?? 'localhost')
        .(isset($appUrl['port']) ? ':'.$appUrl['port'] : '');

    return array_values(array_filter(resourceUrlsIn($html), function (string $url) use ($ownOrigin): bool {
        $relative = str_starts_with($url, '/') && ! str_starts_with($url, '//');
        $ownHost = $url === $ownOrigin || str_starts_with($url, $ownOrigin.'/');

        return ! $relative && ! $ownHost;
    }));
}

dataset('pages without third-party resources', [
    'calendar' => ['/', []],
    'privacy notice' => ['/privacy', []],
    'submission thanks' => ['/submit/thanks', ['submitted' => true, 'reference' => 'WOA-TEST']],
]);

it('loads no third-party resource on the other public pages, even with a Turnstile key set', function (string $path, array $session) {
    $this->withVite();
    app()->usePublicPath(base_path('tests/Fixtures/public'));
    config()->set('services.turnstile.site_key', '1x00000000000000000000AA');

    $html = (string) $this->withSession($session)->get($path)->assertOk()->getContent();

    expect(thirdPartyUrlsIn($html))->toBe([])
        ->and($html)->not->toContain('challenges.cloudflare.com')
        ->and($html)->not->toContain('cf-turnstile')
        ->and(preg_match(THIRD_PARTY_DENY_LIST, $html))->toBe(0);
})->with('pages without third-party resources');

it('allows exactly the Turnstile script on /submit when a site key is set', function () {
    config()->set('services.turnstile.site_key', '1x00000000000000000000AA');

    $html = (string) get('/submit')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $widgets = $document->querySelectorAll('div.cf-turnstile');

    expect(thirdPartyUrlsIn($html))->toBe([TURNSTILE_SCRIPT])
        ->and($widgets->length)->toBe(1)
        ->and((string) $widgets->item(0)->getAttribute('data-action'))->toBe('submit')
        ->and((string) $widgets->item(0)->getAttribute('data-sitekey'))->toBe('1x00000000000000000000AA')
        ->and($document->querySelectorAll('link[rel~="preconnect"], link[rel~="dns-prefetch"]')->length)->toBe(0);
});

it('loads nothing third-party on /submit when no site key is set', function () {
    config()->set('services.turnstile.site_key', null);

    $html = (string) get('/submit')->assertOk()->getContent();

    expect(thirdPartyUrlsIn($html))->toBe([])
        ->and($html)->not->toContain('challenges.cloudflare.com')
        ->and($html)->not->toContain('cf-turnstile');
});
