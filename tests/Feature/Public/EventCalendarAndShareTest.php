<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
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
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * EVT-06 (Google half): a prefilled Google Calendar link per non-cancelled
 * listed date, beside 01-08's .ics link. EVT-07: a share block that works
 * without JavaScript (WhatsApp/Facebook/email links and a link box), with
 * the native "Share…" and "Copy link" buttons hidden until the first-party
 * share module reveals them. Absolute URLs come from config('app.url') only,
 * never the Host header.
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

    $links = googleLinks(get('/events/craft-fair')->assertOk());
    $query = queryOf((string) $links[0]->getAttribute('href'));

    expect($query['dates'])->toBe('20261107/20261109')
        ->and(trim((string) preg_replace('/\s+/', ' ', (string) $links[0]->textContent)))->toBe('Google Calendar for Sat 7 Nov, all day until Sun 8 Nov');
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

/**
 * The first anchor whose href starts with the prefix, or null.
 */
function shareLink(TestResponse $response, string $prefix): ?Element
{
    foreach (shareTestDocument($response)->querySelectorAll('[data-share] a[href]') as $anchor) {
        if (str_starts_with((string) $anchor->getAttribute('href'), $prefix)) {
            return $anchor;
        }
    }

    return null;
}

it('renders WhatsApp, Facebook and email share links and a link box without JavaScript', function () {
    shareTestPublish($this->base);

    $response = get('/events/quiz-night')->assertOk();
    $canonical = $this->appUrl.'/events/quiz-night';
    $text = "Quiz Night – Fri 23 Oct, 7:30pm at Connaught Hall, on What's On Attleborough";

    $whatsapp = shareLink($response, 'https://wa.me/?text=');
    $facebook = shareLink($response, 'https://www.facebook.com/sharer/sharer.php?u=');
    $email = shareLink($response, 'mailto:?subject=');

    expect($whatsapp)->not->toBeNull()
        ->and($facebook)->not->toBeNull()
        ->and($email)->not->toBeNull()
        ->and(trim((string) $whatsapp->textContent))->toBe('WhatsApp')
        ->and(trim((string) $facebook->textContent))->toBe('Facebook')
        ->and(trim((string) $email->textContent))->toBe('Email')
        ->and(rawurldecode(substr((string) $whatsapp->getAttribute('href'), strlen('https://wa.me/?text='))))->toBe($text.' '.$canonical)
        ->and(rawurldecode(substr((string) $facebook->getAttribute('href'), strlen('https://www.facebook.com/sharer/sharer.php?u='))))->toBe($canonical);

    parse_str(substr((string) $email->getAttribute('href'), strlen('mailto:?')), $mail);

    expect($mail['subject'])->toBe("Quiz Night | What's On Attleborough")
        ->and($mail['body'])->toBe($text."\r\n\r\n".$canonical);

    $document = shareTestDocument($response);
    $input = $document->querySelector('[data-share] input[data-share-input]');
    $label = $input !== null ? $document->querySelector('label[for="'.$input->getAttribute('id').'"]') : null;

    expect($input)->not->toBeNull()
        ->and($input->hasAttribute('readonly'))->toBeTrue()
        ->and((string) $input->getAttribute('value'))->toBe($canonical)
        ->and(trim((string) $label?->textContent))->toBe('Link to this event')
        ->and(trim((string) $document->querySelector('#share-heading')?->textContent))->toBe('Share this event');

    $root = $document->querySelector('[data-share]');

    expect((string) $root->getAttribute('data-share-title'))->toBe('Quiz Night')
        ->and((string) $root->getAttribute('data-share-text'))->toBe($text)
        ->and((string) $root->getAttribute('data-share-url'))->toBe($canonical);
});

it('starts the share text with "Cancelled: " when the event is cancelled', function () {
    $event = shareTestPublish($this->base);
    app(ChangeEventStatus::class)($event, EventStatus::Cancelled, $this->admin);

    $root = shareTestDocument(get('/events/quiz-night')->assertOk())->querySelector('[data-share]');

    expect((string) $root->getAttribute('data-share-text'))->toBe("Cancelled: Quiz Night – Fri 23 Oct, 7:30pm at Connaught Hall, on What's On Attleborough");
});

it('names the day in the share text for an all-day event and trims the venue name', function () {
    Venue::whereKey($this->base['venueId'])->update(['name' => 'Connaught Hall ']);
    shareTestPublish($this->base, [
        'title' => 'Craft Fair',
        'startDate' => '2026-11-07',
        'startTime' => null,
        'endTime' => null,
        'allDay' => true,
    ]);

    $root = shareTestDocument(get('/events/craft-fair')->assertOk())->querySelector('[data-share]');

    expect((string) $root->getAttribute('data-share-text'))->toBe("Craft Fair – Sat 7 Nov, all day at Connaught Hall, on What's On Attleborough");
});

it('leaves the date out of the share text when no date is upcoming', function () {
    shareTestPublish($this->base, ['title' => 'Past Quiz', 'startDate' => '2026-10-01']);

    $root = shareTestDocument(get('/events/past-quiz')->assertOk())->querySelector('[data-share]');

    expect((string) $root->getAttribute('data-share-text'))->toBe("Past Quiz at Connaught Hall, on What's On Attleborough");
});

it('ships the native share and copy buttons hidden, with a polite status region and no inline handlers', function () {
    shareTestPublish($this->base);

    $html = (string) get('/events/quiz-night')->assertOk()->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $native = $document->querySelector('[data-share] button[data-share-native]');
    $copy = $document->querySelector('[data-share] button[data-share-copy]');
    $status = $document->querySelector('[data-share] [data-share-status]');

    expect($native)->not->toBeNull()
        ->and(trim((string) $native->textContent))->toBe('Share…')
        ->and($native->hasAttribute('hidden'))->toBeTrue()
        ->and((string) $native->getAttribute('type'))->toBe('button')
        ->and($copy)->not->toBeNull()
        ->and(trim((string) $copy->textContent))->toBe('Copy link')
        ->and($copy->hasAttribute('hidden'))->toBeTrue()
        ->and((string) $copy->getAttribute('type'))->toBe('button')
        ->and($status)->not->toBeNull()
        ->and((string) $status->getAttribute('role'))->toBe('status')
        ->and((string) $status->getAttribute('aria-live'))->toBe('polite')
        ->and(trim((string) $status->textContent))->toBe('');

    foreach ($document->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            expect(str_starts_with(strtolower($attribute->name), 'on'))->toBeFalse("Inline handler {$attribute->name} on <{$element->localName}>");
        }
    }
});

it('never puts the request host into a share link', function () {
    shareTestPublish($this->base);

    $response = $this->withHeader('Host', 'evil.example')->get('/events/quiz-night')->assertOk();
    $hrefs = array_map(
        fn (Element $a): string => rawurldecode((string) $a->getAttribute('href')),
        iterator_to_array(shareTestDocument($response)->querySelectorAll('[data-share] a[href]')),
    );

    expect($hrefs)->toHaveCount(3);

    foreach ($hrefs as $href) {
        expect($href)->not->toContain('evil.example')
            ->and($href)->toContain($this->appUrl.'/events/quiz-night');
    }
});

it('loads exactly one same-origin share module on the event page and still none on /', function () {
    shareTestPublish($this->base);
    $this->withVite();
    app()->usePublicPath(base_path('tests/Fixtures/public'));

    $document = shareTestDocument(get('/events/quiz-night')->assertOk());
    $scripts = array_values(array_filter(
        iterator_to_array($document->querySelectorAll('script')),
        fn (Element $script): bool => (string) $script->getAttribute('type') !== 'application/ld+json',
    ));

    expect($scripts)->toHaveCount(1)
        ->and((string) $scripts[0]->getAttribute('type'))->toBe('module')
        ->and((string) $scripts[0]->getAttribute('src'))->toEndWith('/build/assets/share-fixture.js');

    $appUrl = parse_url($this->appUrl);
    $ownOrigin = ($appUrl['scheme'] ?? 'http').'://'.($appUrl['host'] ?? 'localhost').(isset($appUrl['port']) ? ':'.$appUrl['port'] : '');

    foreach ($document->querySelectorAll('script[src], iframe, img[src], link[href]') as $element) {
        $url = (string) ($element->getAttribute('src') ?? $element->getAttribute('href'));
        $relative = str_starts_with($url, '/') && ! str_starts_with($url, '//');

        expect($relative || str_starts_with($url, $ownOrigin.'/'))->toBeTrue("Third-party resource URL: {$url}");
    }

    expect($document->querySelectorAll('iframe')->length)->toBe(0)
        ->and(shareTestDocument(get('/')->assertOk())->querySelectorAll('script')->length)->toBe(0);
});

it('keeps the share module first-party, dependency-free and plainly worded', function () {
    $source = (string) file_get_contents(base_path('resources/js/share.js'));

    expect($source)->toContain('navigator.share')
        ->and($source)->toContain('AbortError')
        ->and($source)->toContain('isSecureContext')
        ->and($source)->toContain("Sharing didn't work. Use one of the links below.")
        ->and($source)->toContain('Link copied.')
        ->and($source)->toContain("Couldn't copy. Press and hold the link box above to copy it.")
        ->and(preg_match('/innerHTML|eval\(|document\.write|https?:\/\/|^\s*import\s/m', $source))->toBe(0);
});
