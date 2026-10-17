<?php

use App\Enums\EventOrigin;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\UpdatePublishedEvent;
use App\Publishing\Data\PublishableEventData;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * WR-04 (T-01-73): slug redirects stay 301 (permanent for search engines)
 * but carry Cache-Control "no-cache, private", so a browser revalidates
 * instead of replaying a cached redirect. A rename back to an old title
 * then can never trap a visitor in a redirect loop.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'organiserContact' => 'organiser@example.test',
    ];
    $this->appUrl = rtrim((string) config('app.url'), '/');
    $this->event = app(PublishEvent::class)(new PublishableEventData(...$this->base), $this->admin, EventOrigin::Manual);
});

function cachingRename(Event $event, array $base, string $title): Event
{
    return app(UpdatePublishedEvent::class)(
        $event,
        new PublishableEventData(...array_merge($base, ['title' => $title])),
        test()->admin,
    );
}

/**
 * A 301 to $location whose Cache-Control forces revalidation and never
 * grants a positive max-age.
 */
function assertRevalidatedRedirect(TestResponse $response, string $location): void
{
    $response->assertStatus(301)->assertHeader('Location', $location);

    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

    expect($cacheControl)->toContain('no-cache')
        ->and($cacheControl)->toContain('private')
        ->and(preg_match('/max-age=[1-9]/', $cacheControl))->toBe(0);
}

it('sends an old slug to the current one with a 301 that browsers revalidate', function () {
    cachingRename($this->event, $this->base, 'Big Quiz Night');

    assertRevalidatedRedirect(get('/events/quiz-night'), $this->appUrl.'/events/big-quiz-night');
});

it('sends an uppercase slug to the canonical URL with a 301 that browsers revalidate', function () {
    cachingRename($this->event, $this->base, 'Big Quiz Night');

    assertRevalidatedRedirect(get('/events/BIG-QUIZ-NIGHT'), $this->appUrl.'/events/big-quiz-night');
});

it('sends an old-slug .ics URL to the current one with a 301 that browsers revalidate', function () {
    cachingRename($this->event, $this->base, 'Big Quiz Night');

    assertRevalidatedRedirect(
        get('/events/quiz-night/2026-10-23.ics'),
        $this->appUrl.'/events/big-quiz-night/2026-10-23.ics',
    );
});

it('answers the old slug fresh after a rename back, with no redirect loop', function () {
    $event = cachingRename($this->event, $this->base, 'Big Quiz Night');
    cachingRename($event, $this->base, 'Quiz Night');

    assertRevalidatedRedirect(get('/events/big-quiz-night'), $this->appUrl.'/events/quiz-night');
    get('/events/quiz-night')->assertOk();
});
