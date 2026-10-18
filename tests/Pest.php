<?php

use App\Ingestion\Http\HostResolver;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Submissions\Honeypot;
use App\Submissions\TurnstileVerifier;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Tests run on PostgreSQL (phpunit.xml -> attleborough_testing), never SQLite.
| No test may make a real HTTP request, and none may perform real DNS:
| every suite binds a fake HostResolver (every host -> 93.184.215.14, a
| public address) for the ingestion SSRF guard. ingestFakeResolver() changes
| the map for one test.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, ingestResolverFake());
        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, ingestResolverFake());
    })
    ->in('Unit');

/*
 * Browser UAT flows (docs/uat/browser-uat.md) driven by Playwright through
 * pestphp/pest-plugin-browser. The app is served in-process, so time travel,
 * RefreshDatabase and actingAs() apply to the browser's requests. Uses the
 * built assets in public/build (run `npm run build` first).
 *
 * The in-process server reuses one container, so scoped singletons (e.g.
 * Filament's NavigationManager and its badges) would otherwise outlive a
 * request. Forget them after each one, as PHP-FPM and Octane effectively do.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, ingestResolverFake());
        $this->app['events']->listen(RequestHandled::class, fn () => app()->forgetScopedInstances());
    })
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Ingestion fixtures (Phase 3)
|--------------------------------------------------------------------------
*/

/**
 * A HostResolver that never touches DNS. Hosts in $map resolve to the
 * listed addresses (an empty list = the host does not resolve); every other
 * host resolves to 93.184.215.14, a public address.
 *
 * @param  array<string, list<string>>  $map
 */
function ingestResolverFake(array $map = []): HostResolver
{
    return new class($map) implements HostResolver
    {
        /**
         * @param  array<string, list<string>>  $map
         */
        public function __construct(private readonly array $map) {}

        public function resolve(string $host): array
        {
            return $this->map[strtolower($host)] ?? ['93.184.215.14'];
        }
    };
}

/**
 * Override the fake resolver's map for this test, e.g.
 * ingestFakeResolver(['intranet.example' => ['10.0.0.5']]).
 *
 * @param  array<string, list<string>>  $map
 */
function ingestFakeResolver(array $map = []): void
{
    app()->instance(HostResolver::class, ingestResolverFake($map));
}

/**
 * The raw bytes of a captured source fixture, e.g.
 * sourceFixture('attleborough-baptist-church/2026-10-17-ical.ics').
 */
function sourceFixture(string $path): string
{
    $file = base_path('tests/Fixtures/sources/'.$path);

    expect(is_file($file))->toBeTrue("Missing source fixture {$path}");

    return (string) file_get_contents($file);
}

/**
 * The raw bytes of a synthetic ICS fixture in tests/Fixtures/ics (outside the
 * source-manifest scan, C12), e.g. icsFixture('series.ics').
 */
function icsFixture(string $name): string
{
    $file = base_path('tests/Fixtures/ics/'.$name);

    expect(is_file($file))->toBeTrue("Missing ICS fixture {$name}");

    return (string) file_get_contents($file);
}

/*
|--------------------------------------------------------------------------
| Calendar fixtures
|--------------------------------------------------------------------------
*/

/**
 * Freeze time at a Europe/London wall-clock instant.
 */
function freezeLondon(string $localDateTime): void
{
    test()->travelTo(CarbonImmutable::parse($localDateTime, 'Europe/London'));
}

/**
 * A complete, valid POST body for the public /submit form (SUB-01), dated
 * ten days after the frozen London "today". Unless overridden, it picks a
 * new known venue (factory) and the first category by sort order (seed
 * CategorySeeder first, or one is made).
 *
 * Spam-defence fields (02-09): the honeypot `fax_number` is present and
 * empty, and `form_token` is a fresh timing token minted five seconds
 * before "now" (time is put back exactly as it was), so an inline
 * `post('/submit', validSubmission())` is already past the 3-second
 * minimum. Tests that pin the timing mint their own token with
 * Honeypot::mintToken() under travelTo() and travel before posting.
 * Callers still need fakeTurnstilePass() (or their own Http::fake) for the
 * Turnstile check; the `cf-turnstile-response` token defaults to 'tok'.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validSubmission(array $overrides = []): array
{
    $defaults = [
        'title' => 'Spring Craft Fair',
        'description' => 'Local makers selling cards, candles and knitwear. Tea and cake in the side room.',
        'category_choice' => array_key_exists('category_choice', $overrides)
            ? null
            : (string) (Category::query()->orderBy('sort')->value('id') ?? Category::factory()->create()->id),
        'start_date' => CarbonImmutable::now('Europe/London')->addDays(10)->toDateString(),
        'start_time' => '10:00',
        'end_date' => '',
        'end_time' => '15:00',
        'repeats_text' => '',
        'venue_choice' => array_key_exists('venue_choice', $overrides)
            ? null
            : (string) Venue::factory()->create()->id,
        'venue_name' => '',
        'venue_address' => '',
        'venue_postcode' => '',
        'price_type' => 'free',
        'price_text' => '',
        'organiser_name' => 'Attleborough Makers',
        'organiser_email' => 'makers@example.org',
        'organiser_website' => '',
        'source_url' => '',
        'consent' => '1',
        Honeypot::FIELD => '',
        Honeypot::TOKEN => array_key_exists(Honeypot::TOKEN, $overrides) ? null : mintTokenSecondsAgo(5),
        'cf-turnstile-response' => 'tok',
    ];

    return array_merge($defaults, $overrides);
}

/**
 * A timing token minted $seconds before the current (possibly frozen) time.
 * The test clock is restored exactly afterwards.
 */
function mintTokenSecondsAgo(int $seconds): string
{
    $previous = Carbon::getTestNow();

    test()->travelTo(Carbon::now()->subSeconds($seconds));
    $token = Honeypot::mintToken();
    Carbon::setTestNow($previous);

    return $token;
}

/**
 * Make Cloudflare Turnstile pass offline: set a (Cloudflare test) secret
 * and fake siteverify with a successful "submit" answer. Every other URL
 * stays blocked by Http::preventStrayRequests().
 */
function fakeTurnstilePass(): void
{
    config()->set('services.turnstile.secret', '1x0000000000000000000000000000000AA');

    Http::fake([
        TurnstileVerifier::SITEVERIFY_URL => Http::response([
            'success' => true,
            'action' => 'submit',
            'hostname' => 'whatsonattleborough.co.uk',
            'error-codes' => [],
        ]),
    ]);
}

/**
 * Test-only fixture: one event (factory) plus one occurrence inserted
 * directly. App code never writes events outside App\Publishing\Actions.
 *
 * @param  array<string, mixed>  $eventAttributes
 */
function makeOccurrence(
    array $eventAttributes,
    string $startsLocal,
    ?string $endsLocal = null,
    ?string $expiresLocal = null,
    string $status = 'scheduled',
): EventOccurrence {
    $event = Event::factory()->create($eventAttributes);

    $starts = CarbonImmutable::parse($startsLocal, 'Europe/London');
    $ends = $endsLocal !== null ? CarbonImmutable::parse($endsLocal, 'Europe/London') : null;
    $expires = $expiresLocal !== null
        ? CarbonImmutable::parse($expiresLocal, 'Europe/London')
        : ($ends ?? $starts->addHours((int) config('calendar.no_end_expiry_hours')));

    return EventOccurrence::forceCreate([
        'event_id' => $event->id,
        'recurrence_key' => $starts->format('Y-m-d'),
        'starts_at' => $starts->utc(),
        'ends_at' => $ends?->utc(),
        'expires_at' => $expires->utc(),
        'status' => $status,
    ]);
}

/**
 * The <article> blocks (cards) in a response, in document order.
 *
 * @return list<string>
 */
function cardsIn(TestResponse $response): array
{
    preg_match_all('#<article\b.*?</article>#s', (string) $response->getContent(), $matches);

    return $matches[0];
}

/**
 * The event title shown in a card's <h3>: the visible text with the
 * "Cancelled" badge left out, entities decoded. Tolerates the 01-08 title
 * link (<h3><a href="/events/{slug}">Title</a> <strong>Cancelled</strong></h3>).
 */
function cardTitle(string $card): string
{
    if (preg_match('#<h3\b[^>]*>(.*?)</h3>#s', $card, $m) !== 1) {
        return '';
    }

    $inner = (string) preg_replace('#<strong\b.*?</strong>#s', '', $m[1]);

    return html_entity_decode(trim((string) preg_replace('/\s+/u', ' ', strip_tags($inner))));
}

/**
 * Assert the response has an <a> with exactly this href (null = any) and
 * this visible text (whitespace collapsed). $current true requires
 * aria-current="true", false requires no aria-current, null skips the
 * check. Classes and decorative icons inside the link are ignored, so the
 * 01-07 restyle cannot break the 01-06 link contract checks.
 */
function assertLink(TestResponse $response, ?string $href, string $text, ?bool $current = null): TestResponse
{
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    $found = false;

    foreach ($document->querySelectorAll('a') as $anchor) {
        $matches = ($href === null || $anchor->getAttribute('href') === $href)
            && trim((string) preg_replace('/\s+/u', ' ', (string) $anchor->textContent)) === $text
            && match ($current) {
                true => $anchor->getAttribute('aria-current') === 'true',
                false => ! $anchor->hasAttribute('aria-current'),
                null => true,
            };

        if ($matches) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue(sprintf(
        'No <a> with href %s, text "%s"%s.',
        $href === null ? '(any)' : '"'.$href.'"',
        $text,
        match ($current) {
            true => ' and aria-current="true"',
            false => ' and no aria-current',
            null => '',
        },
    ));

    return $response;
}
