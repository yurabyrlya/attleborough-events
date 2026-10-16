<?php

use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Tests run on PostgreSQL (phpunit.xml -> attleborough_testing), never SQLite.
| No test may make a real HTTP request.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
    })
    ->in('Unit');

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
