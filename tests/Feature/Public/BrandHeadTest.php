<?php

use Dom\HTMLDocument;
use Illuminate\Support\Facades\View;

use function Pest\Laravel\get;

/*
 * D-01 / D-07 / D-08 head and header: colour scheme, theme colours, icons,
 * default share metadata built from config('app.url') only (T-01-41) and
 * the SVG wordmark.
 */

/**
 * A colour token from resources/css/app.css (light = @theme static, dark = @variant dark).
 */
function brandHeadToken(string $theme, string $name): string
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $opener = $theme === 'dark' ? '@variant dark' : '@theme static';
    $block = substr($css, (int) strpos($css, $opener));
    $block = substr($block, 0, (int) strpos($block, '}'));

    preg_match('/--color-'.preg_quote($name, '/').'\s*:\s*(#[0-9a-fA-F]{6})/', $block, $m);

    return strtoupper($m[1] ?? '');
}

function brandHeadDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

it('declares light and dark colour schemes with theme colours read from the tokens', function () {
    $document = brandHeadDocument((string) get('/')->assertOk()->getContent());

    $light = $document->querySelector('meta[name="theme-color"][media="(prefers-color-scheme: light)"]');
    $dark = $document->querySelector('meta[name="theme-color"][media="(prefers-color-scheme: dark)"]');

    expect($document->querySelector('meta[name="color-scheme"]')?->getAttribute('content'))->toBe('light dark')
        ->and(brandHeadToken('light', 'accent'))->toBe('#2E6B3F')
        ->and(strtoupper((string) $light?->getAttribute('content')))->toBe(brandHeadToken('light', 'accent'))
        ->and(brandHeadToken('dark', 'bg'))->toBe('#111614')
        ->and(strtoupper((string) $dark?->getAttribute('content')))->toBe(brandHeadToken('dark', 'bg'));
});

it('links the SVG favicon, the ICO fallback and the apple touch icon', function () {
    $document = brandHeadDocument((string) get('/')->assertOk()->getContent());

    expect($document->querySelector('link[rel="icon"][href="/favicon.svg"][type="image/svg+xml"]'))->not->toBeNull()
        ->and($document->querySelector('link[rel="icon"][href="/favicon.ico"][sizes="32x32"]'))->not->toBeNull()
        ->and($document->querySelector('link[rel="apple-touch-icon"][href="/apple-touch-icon.png"]'))->not->toBeNull();
});

it('renders the default Open Graph and Twitter metadata once, escaped once', function () {
    $html = (string) get('/')->assertOk()->getContent();
    $document = brandHeadDocument($html);
    $images = $document->querySelectorAll('meta[property="og:image"]');

    expect($images->length)->toBe(1)
        ->and($images->item(0)->getAttribute('content'))->toBe(rtrim((string) config('app.url'), '/').'/images/og-default.png')
        ->and($document->querySelector('meta[property="og:image:width"]')?->getAttribute('content'))->toBe('1200')
        ->and($document->querySelector('meta[property="og:image:height"]')?->getAttribute('content'))->toBe('630')
        ->and(trim((string) $document->querySelector('meta[property="og:image:alt"]')?->getAttribute('content')))->not->toBe('')
        ->and($document->querySelector('meta[property="og:type"]')?->getAttribute('content'))->toBe('website')
        ->and($document->querySelector('meta[name="twitter:card"]')?->getAttribute('content'))->toBe('summary_large_image')
        ->and($html)->toContain('<meta property="og:site_name" content="What&#039;s On Attleborough">')
        ->and($html)->not->toContain('&amp;#039;');
});

it('never puts the request Host header into the response', function () {
    $html = (string) $this->withHeader('Host', 'evil.example')
        ->get('http://evil.example/')
        ->assertOk()
        ->getContent();

    $ogImage = brandHeadDocument($html)->querySelector('meta[property="og:image"]')?->getAttribute('content');

    expect((string) $ogImage)->toStartWith(rtrim((string) config('app.url'), '/'))
        ->and($html)->not->toContain('evil.example');
});

it('lets a page replace the default share metadata through the og stack', function () {
    View::addLocation(base_path('tests/Fixtures/views'));

    $document = brandHeadDocument(view('brand-og-push')->render());
    $images = $document->querySelectorAll('meta[property="og:image"]');

    expect($images->length)->toBe(1)
        ->and($images->item(0)->getAttribute('content'))->toBe('https://example.test/images/custom.png')
        ->and($document->querySelector('meta[property="og:image:alt"]'))->toBeNull();
});

it('shows the wordmark: a decorative calendar-pin svg and the live site name linking home', function () {
    $document = brandHeadDocument((string) get('/')->assertOk()->getContent());

    $logo = null;

    foreach ($document->querySelectorAll('header a') as $anchor) {
        if ($anchor->getAttribute('href') === route('calendar', absolute: false)) {
            $logo = $anchor;
        }
    }

    expect($logo)->not->toBeNull();

    $svgs = $logo->querySelectorAll('svg');

    expect(trim((string) preg_replace('/\s+/', ' ', (string) $logo->textContent)))->toBe("What's On Attleborough")
        ->and($svgs->length)->toBe(1)
        ->and($svgs->item(0)->getAttribute('aria-hidden'))->toBe('true')
        ->and($svgs->item(0)->getAttribute('focusable'))->toBe('false');

    foreach ($document->querySelectorAll('img') as $img) {
        expect((string) $img->getAttribute('src'))->not->toMatch('#^([a-z][a-z0-9+.-]*:)?//#i');
    }
});

it('keeps the inline calendar-pin identical to the master SVG', function () {
    $pathData = function (string $file): array {
        preg_match_all('/\sd="([^"]+)"/', (string) @file_get_contents(base_path($file)), $m);

        return $m[1];
    };

    $master = $pathData('resources/svg/calendar-pin.svg');

    expect($master)->not->toBeEmpty()
        ->and($pathData('resources/views/components/brand/calendar-pin.blade.php'))->toBe($master);
});

it('ships the favicon, apple touch icon and default share image at the right sizes', function () {
    $favicon = (string) @file_get_contents(public_path('favicon.svg'));
    $ico = (string) @file_get_contents(public_path('favicon.ico'));
    $touch = @getimagesize(public_path('apple-touch-icon.png'));
    $og = @getimagesize(public_path('images/og-default.png'));

    expect($favicon)->toMatch('/<svg\b[^>]*\bviewBox="/')
        ->and(strlen($ico))->toBeGreaterThan(0)
        ->and(substr($ico, 0, 4))->toBe("\x00\x00\x01\x00")
        ->and($touch)->toBeArray()
        ->and([$touch[0] ?? null, $touch[1] ?? null, $touch['mime'] ?? null])->toBe([180, 180, 'image/png'])
        ->and($og)->toBeArray()
        ->and([$og[0] ?? null, $og[1] ?? null, $og['mime'] ?? null])->toBe([1200, 630, 'image/png']);
});
