<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * EVT-02 head: title, meta description, canonical and Open Graph, built
 * from config('app.url') only and escaped exactly once.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    $this->base = [
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz, teams of up to six.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create(['name' => 'Connaught Hall'])->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'endTime' => '22:00:00',
        'organiserContact' => 'organiser@example.test',
    ];
    $this->appUrl = rtrim((string) config('app.url'), '/');
});

function seoPublish(array $base, array $overrides = []): Event
{
    return app(PublishEvent::class)(
        new PublishableEventData(...array_merge($base, $overrides)),
        test()->admin,
        EventOrigin::Manual,
    );
}

function seoHead(TestResponse $response): HTMLDocument
{
    return HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
}

/**
 * @return list<string>
 */
function seoMeta(HTMLDocument $document, string $selector): array
{
    return array_map(
        fn (Element $element): string => (string) $element->getAttribute('content'),
        iterator_to_array($document->querySelectorAll($selector)),
    );
}

it('titles the page with the event, its next date and the site suffix', function () {
    seoPublish($this->base);

    $title = seoHead(get('/events/quiz-night')->assertOk())->querySelector('title')?->textContent;

    expect($title)->toBe("Quiz Night – Fri 23 Oct | What's On Attleborough");
});

it('leaves the date out of the title when nothing is upcoming', function () {
    seoPublish($this->base, ['startDate' => '2026-10-10']);

    expect(seoHead(get('/events/quiz-night')->assertOk())->querySelector('title')?->textContent)
        ->toBe("Quiz Night | What's On Attleborough");
});

it('prefixes a cancelled event title with "Cancelled: "', function () {
    $event = seoPublish($this->base);
    app(ChangeEventStatus::class)($event, EventStatus::Cancelled, $this->admin);

    $document = seoHead(get('/events/quiz-night')->assertOk());

    expect($document->querySelector('title')?->textContent)->toStartWith('Cancelled: Quiz Night – Fri 23 Oct')
        ->and(seoMeta($document, 'meta[property="og:title"]'))->toBe(['Cancelled: Quiz Night']);
});

it('writes one meta description from the next date, the venue and the description', function () {
    seoPublish($this->base);

    expect(seoMeta(seoHead(get('/events/quiz-night')->assertOk()), 'meta[name="description"]'))
        ->toBe(['Fri 23 Oct, 7:30pm at Connaught Hall. General knowledge quiz, teams of up to six.']);
});

it('falls back to the site tagline when the description is empty', function () {
    $event = seoPublish($this->base, ['title' => '!!!']);
    // 01-12's DTO refuses blank descriptions; the page must still handle a stored one (importers, SQL).
    Event::query()->whereKey($event->id)->update(['description' => " \n "]);

    $document = seoHead(get('/events/event')->assertOk());

    expect(seoMeta($document, 'meta[name="description"]'))->toBe([config('site.tagline')])
        ->and(trim((string) $document->querySelector('title')?->textContent))->not->toBe('');
});

it('has a self-referencing canonical from config that ignores tracking parameters', function () {
    seoPublish($this->base);

    foreach (['/events/quiz-night', '/events/quiz-night?fbclid=abc&utm_source=x'] as $uri) {
        $document = seoHead(get($uri)->assertOk());
        $canonicals = array_map(
            fn (Element $link): string => (string) $link->getAttribute('href'),
            iterator_to_array($document->querySelectorAll('link[rel="canonical"]')),
        );

        expect($canonicals)->toBe([$this->appUrl.'/events/quiz-night'])
            ->and($document->querySelector('meta[name="robots"]'))->toBeNull();
    }
});

it('replaces the default share metadata with the event\'s own Open Graph tags', function () {
    seoPublish($this->base);

    $document = seoHead(get('/events/quiz-night')->assertOk());

    expect(seoMeta($document, 'meta[property="og:image"]'))->toBe([$this->appUrl.'/images/og-default.png'])
        ->and(seoMeta($document, 'meta[property="og:image:width"]'))->toBe(['1200'])
        ->and(seoMeta($document, 'meta[property="og:image:height"]'))->toBe(['630'])
        ->and(seoMeta($document, 'meta[property="og:image:alt"]'))->toHaveCount(1)
        ->and(seoMeta($document, 'meta[property="og:title"]'))->toBe(['Quiz Night'])
        ->and(seoMeta($document, 'meta[property="og:url"]'))->toBe([$this->appUrl.'/events/quiz-night'])
        ->and(seoMeta($document, 'meta[property="og:site_name"]'))->toBe(["What's On Attleborough"])
        ->and(seoMeta($document, 'meta[property="og:type"]'))->toBe(['website'])
        ->and(seoMeta($document, 'meta[property="og:description"]'))->toBe(['Fri 23 Oct, 7:30pm at Connaught Hall. General knowledge quiz, teams of up to six.'])
        ->and(seoMeta($document, 'meta[name="twitter:card"]'))->toBe(['summary_large_image']);
});

it('never echoes the request Host header', function () {
    seoPublish($this->base);

    $response = $this->withHeader('Host', 'evil.example')->get('/events/quiz-night')->assertOk();

    expect((string) $response->getContent())->not->toContain('evil.example');

    $ics = $this->withHeader('Host', 'evil.example')->get('/events/quiz-night/2026-10-23.ics')->assertOk();

    expect((string) $ics->getContent())->not->toContain('evil.example');
});

it('escapes the title exactly once in the title tag and og:title', function () {
    seoPublish($this->base, ['title' => 'Fish & Chips']);

    $response = get('/events/fish-chips')->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('<title>Fish &amp; Chips – Fri 23 Oct')
        ->and($html)->toContain('content="Fish &amp; Chips"')
        ->and($html)->not->toContain('&amp;amp;');
});

it('cuts the meta description at 160 grapheme clusters without splitting an emoji sequence', function () {
    $family = "\u{1F469}\u{200D}\u{1F469}\u{200D}\u{1F467}";
    seoPublish($this->base, ['description' => str_repeat($family.' ', 100)]);

    $description = seoMeta(seoHead(get('/events/quiz-night')->assertOk()), 'meta[name="description"]')[0];
    $withoutEllipsis = mb_substr($description, 0, -1);

    expect(grapheme_strlen($description))->toBeLessThanOrEqual(160)
        ->and($description)->toEndWith('…')
        ->and(mb_check_encoding($description, 'UTF-8'))->toBeTrue()
        ->and($withoutEllipsis)->toEndWith($family);
});

it('transliterates accented titles into an ASCII slug', function () {
    $event = seoPublish($this->base, ['title' => 'Fête de Noël – Café']);

    expect($event->slug)->toBe('fete-de-noel-cafe');
    get('/events/fete-de-noel-cafe')->assertOk()->assertSee('Fête de Noël – Café');
});
