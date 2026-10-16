<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * EVT-01: everything a resident needs on the event page. Optional fields
 * leave no label and no empty element; text is escaped exactly once.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz, teams of up to six.',
        'categoryId' => Category::factory()->create(['name' => 'Community', 'slug' => 'community'])->id,
        'venueId' => Venue::factory()->create([
            'name' => 'Connaught Hall',
            'address' => 'Station Road',
            'town' => 'Attleborough',
            'postcode' => 'NR17 2AS',
        ])->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'endTime' => '22:00:00',
        'organiserName' => 'Attleborough Lions',
        'organiserContact' => 'lions@example.org',
        'sourceUrl' => 'https://www.example.org/quiz',
        'sourceNote' => 'From the club newsletter',
    ];
});

function contentPublish(array $base, array $overrides = []): Event
{
    return app(PublishEvent::class)(
        new PublishableEventData(...array_merge($base, $overrides)),
        test()->admin,
        EventOrigin::Manual,
    );
}

function contentMain(TestResponse $response): Dom\Element
{
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    $main = $document->querySelector('main');
    expect($main)->not->toBeNull();

    return $main;
}

function contentText(?Dom\Node $node): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $node?->textContent));
}

it('shows the venue with its address, the organiser, a mailto contact and the attribution', function () {
    contentPublish($this->base);

    $response = get('/events/quiz-night')->assertOk();
    $main = contentMain($response);
    $text = contentText($main);

    expect($text)->toContain('Connaught Hall')
        ->and($text)->toContain('Station Road')
        ->and($text)->toContain('Attleborough')
        ->and($text)->toContain('NR17 2AS')
        ->and($text)->toContain('Organised by Attleborough Lions')
        ->and($text)->toContain('Source: From the club newsletter')
        ->and($text)->toContain('More information: example.org')
        ->and($text)->not->toContain('Listed by What\'s On Attleborough.');

    assertLink($response, 'mailto:lions@example.org', 'lions@example.org');
    assertLink($response, 'https://www.example.org/quiz', 'example.org');

    $source = $main->querySelector('a[href="https://www.example.org/quiz"]');
    $rel = explode(' ', (string) $source?->getAttribute('rel'));

    expect($rel)->toContain('nofollow')->and($rel)->toContain('noopener');
});

it('shows the category, the price label and the family-friendly tag', function () {
    contentPublish($this->base, ['priceType' => PriceType::Paid, 'priceText' => '£3 per person', 'familyFriendly' => true]);

    $text = contentText(contentMain(get('/events/quiz-night')->assertOk()));

    expect($text)->toContain('Community')
        ->and($text)->toContain('£3 per person')
        ->and($text)->toContain('Family-friendly');
});

it('links a phone-like contact with tel:', function () {
    contentPublish($this->base, ['organiserContact' => '01953 123456']);

    assertLink(get('/events/quiz-night')->assertOk(), 'tel:01953123456', '01953 123456');
});

it('shows any other contact as plain text without a link', function () {
    contentPublish($this->base, ['organiserContact' => 'ask at the bar']);

    $main = contentMain(get('/events/quiz-night')->assertOk());

    expect(contentText($main))->toContain('Contact: ask at the bar');

    foreach ($main->querySelectorAll('a') as $anchor) {
        expect(contentText($anchor))->not->toBe('ask at the bar');
    }
});

it('never turns a non-http source URL into a link', function () {
    contentPublish($this->base, ['sourceUrl' => 'javascript:alert(1)', 'sourceNote' => null]);

    $response = get('/events/quiz-night')->assertOk();

    expect((string) $response->getContent())->not->toContain('href="javascript:')
        ->and(contentText(contentMain($response)))->not->toContain('More information')
        ->and(contentText(contentMain($response)))->toContain("Listed by What's On Attleborough.");
});

it('leaves no label or empty element for blank optional fields and falls back to "Listed by"', function () {
    contentPublish($this->base, [
        'organiserName' => null,
        'organiserContact' => null,
        'sourceUrl' => null,
        'sourceNote' => null,
        'priceText' => null,
    ]);

    $main = contentMain(get('/events/quiz-night')->assertOk());
    $text = contentText($main);

    expect($text)->not->toContain('Organised by')
        ->and($text)->not->toContain('Contact')
        ->and($text)->not->toContain('Source:')
        ->and($text)->not->toContain('More information')
        ->and($text)->toContain("Listed by What's On Attleborough.");

    foreach ($main->querySelectorAll('p, li, dd, dt') as $element) {
        expect(contentText($element))->not->toBe('', 'Empty <'.strtolower($element->tagName).'> left on the page.');
    }
});

it('renders the full description as escaped text once, keeping line breaks and wrapping anywhere', function () {
    contentPublish($this->base, ['description' => "Line one\n\nLine <b>two</b> & 🎉 café"]);

    $response = get('/events/quiz-night')->assertOk();
    preg_match('#<main\b.*?</main>#s', (string) $response->getContent(), $main);

    expect(substr_count($main[0], 'Line &lt;b&gt;two&lt;/b&gt; &amp; 🎉 café'))->toBe(1)
        ->and($main[0])->not->toContain('<b>two</b>')
        ->and($main[0])->not->toContain('&amp;amp;');

    $paragraph = null;
    foreach (contentMain($response)->querySelectorAll('p') as $p) {
        if (str_contains((string) $p->textContent, 'Line one')) {
            $paragraph = $p;
        }
    }

    $classes = explode(' ', (string) $paragraph?->getAttribute('class'));

    expect($paragraph?->textContent)->toContain("Line one\n\nLine")
        ->and($classes)->toContain('whitespace-pre-line')
        ->and($classes)->toContain('wrap-anywhere');
});

it('does not turn a long URL in the description into a link', function () {
    $url = 'https://example.org/'.str_repeat('a', 180);
    contentPublish($this->base, ['description' => 'Details: '.$url]);

    $main = contentMain(get('/events/quiz-night')->assertOk());

    expect(contentText($main))->toContain($url)
        ->and($main->querySelector('a[href="'.$url.'"]'))->toBeNull();
});

it('lists the next 10 dates of a series and says how many more follow', function (int $count, string $more) {
    contentPublish($this->base, [
        'startTime' => '10:00:00',
        'endTime' => null,
        'repeats' => RepeatPattern::Weekly,
        'repeatEnds' => RepeatEnds::AfterCount,
        'repeatCount' => $count,
    ]);

    $main = contentMain(get('/events/quiz-night')->assertOk());

    expect(contentText($main))->toContain('Upcoming dates')
        ->and(contentText($main))->toContain($more)
        ->and($main->querySelectorAll('.dates time'))->toHaveCount(10);
})->with([
    'twelve dates' => [12, '2 more dates after these.'],
    'eleven dates' => [11, '1 more date after these.'],
]);

it('shows a one-off date under "When"', function () {
    contentPublish($this->base);

    $main = contentMain(get('/events/quiz-night')->assertOk());
    $headings = array_map(contentText(...), iterator_to_array($main->querySelectorAll('h2')));

    expect($headings)->toContain('When')
        ->and($headings)->not->toContain('Upcoming dates')
        ->and(contentText($main))->not->toContain('more date');
});

it('keeps an event with no upcoming dates at 200 with "No upcoming dates." and no add link', function () {
    contentPublish($this->base, ['startDate' => '2026-10-10']);

    $response = get('/events/quiz-night')->assertOk();

    expect(contentText(contentMain($response)))->toContain('No upcoming dates.')
        ->and((string) $response->getContent())->not->toContain('.ics')
        ->and((string) $response->getContent())->not->toContain('noindex');
});

it('marks a cancelled event in the heading and with a plain sentence', function () {
    $event = contentPublish($this->base);
    app(ChangeEventStatus::class)($event, EventStatus::Cancelled, $this->admin);

    $main = contentMain(get('/events/quiz-night')->assertOk());

    expect(contentText($main->querySelector('h1')))->toContain('Cancelled')
        ->and(contentText($main))->toContain('This event has been cancelled.')
        ->and((string) $main->ownerDocument?->saveHtml($main))->not->toContain('.ics"');
});
