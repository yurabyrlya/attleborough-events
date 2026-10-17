<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * EVT-03: one Schema.org Event JSON-LD script per listed upcoming date (the
 * same at most 10 as the dates list), with eventStatus, a London offset
 * across the 25 Oct 2026 clock change, and absolute URLs from
 * config('app.url') only. Nothing on /.
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

function ldPublish(array $base, array $overrides = []): Event
{
    return app(PublishEvent::class)(
        new PublishableEventData(...array_merge($base, $overrides)),
        test()->admin,
        EventOrigin::Manual,
    );
}

function ldWeekly(array $base, array $overrides = []): Event
{
    return ldPublish($base, array_merge([
        'repeats' => RepeatPattern::Weekly,
        'repeatEnds' => RepeatEnds::AfterCount,
        'repeatCount' => 12,
    ], $overrides));
}

/**
 * The decoded JSON-LD objects on a page, in document order.
 *
 * @return list<array<string, mixed>>
 */
function ldObjects(TestResponse $response): array
{
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    $objects = [];

    foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
        $objects[] = json_decode((string) $script->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    return $objects;
}

it('emits one Event script per listed date, at most 10, in London time across the clock change', function () {
    ldWeekly($this->base);

    $objects = ldObjects(get('/events/quiz-night')->assertOk());
    $canonical = $this->appUrl.'/events/quiz-night';

    expect($objects)->toHaveCount(10)
        ->and($objects[0]['@type'])->toBe('Event')
        ->and($objects[0]['name'])->toBe('Quiz Night')
        ->and($objects[0]['startDate'])->toBe('2026-10-23T19:30:00+01:00')
        ->and($objects[0]['endDate'])->toBe('2026-10-23T22:00:00+01:00')
        ->and($objects[1]['startDate'])->toBe('2026-10-30T19:30:00+00:00');

    foreach ($objects as $object) {
        expect($object['@type'])->toBe('Event')
            ->and($object['location']['@type'])->toBe('Place')
            ->and($object['location']['name'])->toBe('Connaught Hall')
            ->and($object['location']['address']['@type'])->toBe('PostalAddress')
            ->and($object['location']['address']['streetAddress'])->toBe('Station Road')
            ->and($object['location']['address']['addressLocality'])->toBe('Attleborough')
            ->and($object['location']['address']['postalCode'])->toBe('NR17 2AS')
            ->and($object['location']['address']['addressCountry'])->toBe('GB')
            ->and($object['url'])->toBe($canonical)
            ->and($object['image'])->toBe($this->appUrl.'/images/og-default.png')
            ->and($object['eventStatus'])->toBe('https://schema.org/EventScheduled')
            ->and($object['eventAttendanceMode'])->toBe('https://schema.org/OfflineEventAttendanceMode');
    }
});

it('marks a cancelled date EventCancelled, keeps its properties and drops a hidden date', function () {
    $event = ldWeekly($this->base);
    $occurrences = EventOccurrence::where('event_id', $event->id)->orderBy('starts_at')->get();
    app(ChangeOccurrenceStatus::class)($occurrences[1], OccurrenceStatus::Cancelled, $this->admin);
    app(ChangeOccurrenceStatus::class)($occurrences[2], OccurrenceStatus::Hidden, $this->admin);

    $objects = ldObjects(get('/events/quiz-night')->assertOk());
    $starts = array_column($objects, 'startDate');

    expect($objects)->toHaveCount(10)
        ->and($objects[0]['eventStatus'])->toBe('https://schema.org/EventScheduled')
        ->and($objects[1]['eventStatus'])->toBe('https://schema.org/EventCancelled')
        ->and($objects[1]['startDate'])->toBe('2026-10-30T19:30:00+00:00')
        ->and($objects[1]['location']['name'])->toBe('Connaught Hall')
        ->and($starts)->not->toContain('2026-11-06T19:30:00+00:00')
        ->and($objects[2]['eventStatus'])->toBe('https://schema.org/EventScheduled');
});

it('marks every date EventCancelled when the whole event is cancelled', function () {
    $event = ldWeekly($this->base);
    app(ChangeEventStatus::class)($event, EventStatus::Cancelled, $this->admin);

    $statuses = array_unique(array_column(ldObjects(get('/events/quiz-night')->assertOk()), 'eventStatus'));

    expect($statuses)->toBe(['https://schema.org/EventCancelled']);
});

it('uses date strings with an inclusive end for an all-day event', function () {
    ldPublish($this->base, [
        'title' => 'Craft Fair',
        'startDate' => '2026-11-07',
        'endDate' => '2026-11-08',
        'startTime' => null,
        'endTime' => null,
        'allDay' => true,
    ]);

    $objects = ldObjects(get('/events/craft-fair')->assertOk());

    expect($objects)->toHaveCount(1)
        ->and($objects[0]['startDate'])->toBe('2026-11-07')
        ->and($objects[0]['endDate'])->toBe('2026-11-08');
});

it('leaves endDate out when no finish time is given', function () {
    ldPublish($this->base, ['endTime' => null]);

    $objects = ldObjects(get('/events/quiz-night')->assertOk());

    expect($objects)->toHaveCount(1)
        ->and($objects[0])->not->toHaveKey('endDate');
});

it('adds a GBP 0 offer and isAccessibleForFree only for free events', function () {
    ldPublish($this->base, ['priceType' => PriceType::Free]);
    ldPublish($this->base, ['title' => 'Paid Quiz', 'priceType' => PriceType::Paid, 'priceText' => '£5']);

    $free = ldObjects(get('/events/quiz-night')->assertOk())[0];
    $paid = ldObjects(get('/events/paid-quiz')->assertOk())[0];

    expect($free['isAccessibleForFree'])->toBeTrue()
        ->and($free['offers']['@type'])->toBe('Offer')
        ->and($free['offers']['price'])->toBe(0)
        ->and($free['offers']['priceCurrency'])->toBe('GBP')
        ->and($free['offers']['url'])->toBe($this->appUrl.'/events/quiz-night')
        ->and($paid)->not->toHaveKey('offers')
        ->and($paid)->not->toHaveKey('isAccessibleForFree');
});

it('cannot be broken out of by a script tag in the title', function () {
    $title = '</script><script>alert(1)</script>';
    $event = ldPublish($this->base, ['title' => $title]);

    $response = get('/events/'.$event->slug)->assertOk();

    expect((string) $response->getContent())->not->toContain('<script>alert')
        ->and(ldObjects($response)[0]['name'])->toBe($title);
});

it('emits no JSON-LD on / or for an event with no upcoming dates', function () {
    ldPublish($this->base);
    ldPublish($this->base, ['title' => 'Past Quiz', 'startDate' => '2026-10-01']);

    expect((string) get('/')->assertOk()->getContent())->not->toContain('application/ld+json')
        ->and(ldObjects(get('/events/past-quiz')->assertOk()))->toBe([]);
});

it('never puts the request host into the JSON-LD', function () {
    ldWeekly($this->base);

    $response = $this->withHeader('Host', 'evil.example')->get('/events/quiz-night')->assertOk();
    $objects = ldObjects($response);

    expect($objects)->toHaveCount(10)
        ->and(json_encode($objects))->not->toContain('evil.example')
        ->and($objects[0]['url'])->toBe($this->appUrl.'/events/quiz-night');
});
