<?php

use App\Enums\EventOrigin;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * EVT-06 (Google half): a prefilled Google Calendar link per non-cancelled
 * listed date, beside 01-08's .ics link. Absolute URLs come from
 * config('app.url') only, never the Host header.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz, teams of up to six.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create([
            'name' => 'Connaught Hall',
            'address' => 'Station Road',
            'town' => 'Attleborough',
            'postcode' => 'NR17 2AS',
        ])->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'endTime' => '22:00:00',
    ];
    $this->appUrl = rtrim((string) config('app.url'), '/');
});

function shareTestPublish(array $base, array $overrides = []): Event
{
    return app(PublishEvent::class)(
        new PublishableEventData(...array_merge($base, $overrides)),
        test()->admin,
        EventOrigin::Manual,
    );
}

function shareTestDocument(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
}

/**
 * The Google Calendar anchors on a page, in document order.
 *
 * @return list<Element>
 */
function googleLinks(TestResponse $response): array
{
    return array_values(array_filter(
        iterator_to_array(shareTestDocument($response)->querySelectorAll('a[href]')),
        fn (Element $anchor): bool => str_starts_with((string) $anchor->getAttribute('href'), 'https://calendar.google.com/'),
    ));
}

/**
 * The decoded query of a URL.
 *
 * @return array<string, string>
 */
function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('links each date to a prefilled Google Calendar template in UTC', function () {
    shareTestPublish($this->base);

    $links = googleLinks(get('/events/quiz-night')->assertOk());

    expect($links)->toHaveCount(1);

    $href = (string) $links[0]->getAttribute('href');
    $query = queryOf($href);

    expect($href)->toStartWith('https://calendar.google.com/calendar/render?action=TEMPLATE&')
        ->and($href)->toContain('text=Quiz%20Night')
        ->and($href)->not->toContain('+')
        ->and($query['text'])->toBe('Quiz Night')
        ->and($query['dates'])->toBe('20261023T183000Z/20261023T210000Z')
        ->and($query['location'])->toBe('Connaught Hall, Station Road, Attleborough, NR17 2AS')
        ->and($query['details'])->toStartWith('General knowledge quiz, teams of up to six.')
        ->and($query['details'])->toEndWith($this->appUrl.'/events/quiz-night')
        ->and(trim((string) preg_replace('/\s+/', ' ', (string) $links[0]->textContent)))->toBe('Google Calendar for Fri 23 Oct, 7:30pm')
        ->and((string) $links[0]->getAttribute('rel'))->toBe('nofollow noopener')
        ->and($links[0]->querySelector('.sr-only')?->textContent)->toBe(' for Fri 23 Oct, 7:30pm');
});

it('uses the expiry and a "Finish time not given." line when there is no end', function () {
    shareTestPublish($this->base, ['endTime' => null]);

    $query = queryOf((string) googleLinks(get('/events/quiz-night')->assertOk())[0]->getAttribute('href'));

    expect($query['dates'])->toBe('20261023T183000Z/20261023T213000Z')
        ->and($query['details'])->toStartWith("Finish time not given.\n");
});

it('uses whole dates with an exclusive end for an all-day event', function () {
    shareTestPublish($this->base, [
        'title' => 'Craft Fair',
        'startDate' => '2026-11-07',
        'endDate' => '2026-11-08',
        'startTime' => null,
        'endTime' => null,
        'allDay' => true,
    ]);

    $query = queryOf((string) googleLinks(get('/events/craft-fair')->assertOk())[0]->getAttribute('href'));

    expect($query['dates'])->toBe('20261107/20261109');
});

it('keeps UTC across the clock change and offers no add links for a cancelled date', function () {
    $event = shareTestPublish($this->base, ['repeats' => RepeatPattern::Weekly]);
    $occurrence = EventOccurrence::where('event_id', $event->id)->where('recurrence_key', '2026-10-30')->firstOrFail();
    app(ChangeOccurrenceStatus::class)($occurrence, OccurrenceStatus::Cancelled, $this->admin);

    $response = get('/events/quiz-night')->assertOk();
    $dates = array_map(fn (Element $a): string => queryOf((string) $a->getAttribute('href'))['dates'], googleLinks($response));

    preg_match('#<li\b[^>]*>(?:(?!</li>).)*datetime="2026-10-30T19:30:00\+00:00".*?</li>#s', (string) $response->getContent(), $item);

    expect($dates[0])->toBe('20261023T183000Z/20261023T210000Z')
        ->and($dates[1])->toBe('20261106T193000Z/20261106T220000Z')
        ->and($dates)->not->toContain('20261030T193000Z/20261030T220000Z')
        ->and($item[0] ?? '')->toContain('Cancelled')
        ->and($item[0] ?? '')->not->toContain('calendar.google.com')
        ->and($item[0] ?? '')->not->toContain('.ics');
});

it('never puts the request host into the Google link', function () {
    shareTestPublish($this->base);

    $response = $this->withHeader('Host', 'evil.example')->get('/events/quiz-night')->assertOk();
    $links = googleLinks($response);

    expect($links)->toHaveCount(1)
        ->and((string) $links[0]->getAttribute('href'))->not->toContain('evil.example')
        ->and((string) $response->getContent())->not->toContain('evil.example');
});
