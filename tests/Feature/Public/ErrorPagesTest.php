<?php

use Database\Seeders\CategorySeeder;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\get;

/*
 * Branded, static-safe error pages (UI review fix 1). A dead shared link
 * lands on a page that looks like the site and has a way back. The 500 and
 * 503 views must render while the database or built assets are broken, so
 * they run no queries, call no route() and only include Vite CSS when a
 * manifest (or hot file) exists. They never echo an exception message.
 */

beforeEach(function () {
    $this->seed(CategorySeeder::class);
    freezeLondon('2026-10-28 12:00');
});

function errorDocument(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

function expectBrandedErrorPage(string $html, string $heading): void
{
    $document = errorDocument($html);
    $home = $document->querySelector('main a[href="/"]');

    expect($document->querySelector('title')?->textContent)->toContain(config('site.name'))
        ->and($document->querySelector('header a[href="/"]')?->textContent)->toContain(config('site.name'))
        ->and($document->querySelector('main#main h1')?->textContent)->toBe($heading)
        ->and(trim((string) $home?->textContent))->toBe("See what's on")
        ->and($home?->getAttribute('class'))->toContain('tap-target')
        ->and(trim((string) $document->querySelector('body a[href="#main"]')?->textContent))->toBe('Skip to main content')
        ->and($document->querySelector('meta[name="robots"]')?->getAttribute('content'))->toBe('noindex')
        ->and($document->querySelectorAll('script')->length)->toBe(0);
}

it('shows the branded 404 for an unknown event slug', function () {
    $html = (string) get('/events/no-such-thing')->assertNotFound()->getContent();

    expectBrandedErrorPage($html, "We can't find that page");
});

it('shows the branded 404 for a hidden event', function () {
    makeOccurrence(['status' => 'hidden', 'slug' => 'quiz-night', 'title' => 'Quiz Night'], '2026-10-31 19:30');

    $html = (string) get('/events/quiz-night')->assertNotFound()->getContent();

    expectBrandedErrorPage($html, "We can't find that page");
    expect($html)->not->toContain('Quiz Night');
});

it('shows the branded 404 for an unknown category without echoing it', function () {
    $html = (string) get('/?category=nonexistent')->assertNotFound()->getContent();

    expectBrandedErrorPage($html, "We can't find that page");
    expect($html)->not->toContain('nonexistent');
});

it('renders the 500 and 503 pages with no queries and no exception detail', function (string $view, string $heading) {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $html = view($view, ['exception' => new RuntimeException('SQLSTATE secret connection detail')])->render();

    expect($queries)->toBe(0);
    expectBrandedErrorPage($html, $heading);
    expect($html)->not->toContain('SQLSTATE')
        ->and($html)->not->toContain('secret connection detail');
})->with([
    '500' => ['errors.500', 'Sorry, something went wrong on our side'],
    '503' => ['errors.503', "We're updating the calendar"],
]);

it('still renders the 500 page when the Vite manifest is missing', function () {
    $this->withVite();
    $original = public_path();
    $empty = sys_get_temp_dir().'/ae-error-pages-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($empty);
    app()->usePublicPath($empty);

    try {
        $html = view('errors.500')->render();
    } finally {
        app()->usePublicPath($original);
        File::deleteDirectory($empty);
    }

    $document = errorDocument($html);

    expect(trim((string) $document->querySelector('main a[href="/"]')?->textContent))->toBe("See what's on")
        ->and($document->querySelector('main#main h1')?->textContent)->toBe('Sorry, something went wrong on our side')
        ->and($document->querySelectorAll('link[rel="stylesheet"]')->length)->toBe(0);
});
