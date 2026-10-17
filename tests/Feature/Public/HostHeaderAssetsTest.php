<?php

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

/*
 * CR-01 (T-01-70): no URL the app generates depends on the request Host
 * header. The URL generator root is pinned to config('app.url') and the
 * Vite tags are root-relative, so a spoofed Host cannot poison a shared
 * cache. Vite is enabled here (fixture manifest), unlike the global
 * withoutVite() default, because the poisoned URLs were the Vite tags.
 *
 * Spoofing note: Laravel's test client rebuilds HTTP_HOST from the request
 * URI (relative paths get config('app.url')'s host), so withHeader('Host')
 * alone never reaches the app. spoofedHostGet() also requests the absolute
 * http://evil.example URI, which is what the app sees when a client sends
 * "Host: evil.example" to the real server.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->withVite();
    app()->usePublicPath(base_path('tests/Fixtures/public'));

    $admin = User::factory()->create(['email' => config('app.admin_email')]);

    app(PublishEvent::class)(
        new PublishableEventData(
            title: 'Quiz Night',
            description: 'General knowledge quiz, teams of up to six.',
            categoryId: Category::factory()->create()->id,
            venueId: Venue::factory()->create(['name' => 'Connaught Hall'])->id,
            startDate: '2026-10-23',
            startTime: '19:30:00',
            endTime: '22:00:00',
            organiserContact: 'organiser@example.test',
        ),
        $admin,
        EventOrigin::Manual,
    );

    $this->appUrl = rtrim((string) config('app.url'), '/');
});

function spoofedHostGet(string $path): TestResponse
{
    return test()->withHeader('Host', 'evil.example')->get('http://evil.example'.$path);
}

it('keeps the calendar page free of a spoofed Host and links the stylesheet root-relative', function () {
    $html = (string) spoofedHostGet('/')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $stylesheets = $document->querySelectorAll('link[rel="stylesheet"]');

    expect($html)->not->toContain('evil.example')
        ->and($stylesheets->length)->toBe(1)
        ->and((string) $stylesheets->item(0)->getAttribute('href'))->toStartWith('/build/assets/')
        ->and((string) $stylesheets->item(0)->getAttribute('href'))->toEndWith('app-fixture.css');
});

it('keeps the event page free of a spoofed Host and loads the share module root-relative', function () {
    $html = (string) spoofedHostGet('/events/quiz-night')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $scripts = $document->querySelectorAll('script[src]');
    $canonical = $document->querySelector('link[rel="canonical"]');

    expect($html)->not->toContain('evil.example')
        ->and($scripts->length)->toBe(1)
        ->and((string) $scripts->item(0)->getAttribute('src'))->toStartWith('/build/assets/')
        ->and((string) $scripts->item(0)->getAttribute('src'))->toEndWith('share-fixture.js')
        ->and($canonical?->getAttribute('href'))->toBe($this->appUrl.'/events/quiz-night');
});

it('builds generic redirects such as the admin login from app.url, not the Host header', function () {
    $response = spoofedHostGet('/admin')->assertRedirect();
    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith($this->appUrl)
        ->and($location)->not->toContain('evil.example');
});
