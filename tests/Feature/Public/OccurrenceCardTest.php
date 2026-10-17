<?php

use App\Enums\PriceType;
use App\Models\Category;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Carbon\CarbonImmutable;

use function Pest\Laravel\get;

/*
 * CAL-07: every card field, text "Cancelled" badge, safe links, excerpt
 * rules, and no empty elements.
 */

beforeEach(function () {
    freezeLondon('2026-10-28 12:00');
});

/**
 * The single card on / for a fixture created with makeOccurrence().
 *
 * @param  array<string, mixed>  $attributes
 */
function onlyCard(array $attributes, string $start = '2026-10-31 19:30', string $status = 'scheduled'): string
{
    makeOccurrence($attributes, $start, status: $status);
    $cards = cardsIn(get('/')->assertOk());
    expect($cards)->toHaveCount(1);

    return $cards[0];
}

function excerptOf(string $card): ?string
{
    return preg_match('#<p class="excerpt(?: [^"]*)?">(.*?)</p>#s', $card, $m) ? html_entity_decode($m[1]) : null;
}

it('shows every CAL-07 field on the card', function () {
    $venue = Venue::factory()->create(['name' => 'Connaught Hall']);
    $category = Category::factory()->create(['name' => 'Music', 'slug' => 'music']);

    $card = onlyCard([
        'title' => 'Brass Band Concert',
        'venue_id' => $venue->id,
        'category_id' => $category->id,
        'description' => 'An evening of marches and film themes.',
        'organiser_name' => 'Attleborough Brass',
        'family_friendly' => true,
        'price_type' => PriceType::Free,
    ]);

    expect($card)
        ->toContain('Brass Band Concert')
        ->toContain('<time datetime="2026-10-31T19:30:00+00:00">Sat 31 Oct, 7:30pm</time>')
        ->toContain('Connaught Hall')
        ->toContain('An evening of marches and film themes.')
        ->toContain('Music')
        ->toContain('By Attleborough Brass')
        ->toContain('Family-friendly')
        ->toContain('Free');
});

it('labels the price from price_type and price_text', function (array $attributes, ?string $label) {
    $card = onlyCard($attributes);

    if ($label === null) {
        expect($card)->not->toMatch('#class="price[ "]#');
    } else {
        expect($card)->toMatch('#<p class="price(?: [^"]*)?">'.preg_quote(e($label), '#').'</p>#');
    }
})->with([
    'free' => [['price_type' => PriceType::Free, 'price_text' => null], 'Free'],
    'paid with text, verbatim' => [['price_type' => PriceType::Paid, 'price_text' => '£2.50 adults, £1 children'], '£2.50 adults, £1 children'],
    'paid without text' => [['price_type' => PriceType::Paid, 'price_text' => null], 'Paid'],
    'unknown with text' => [['price_type' => PriceType::Unknown, 'price_text' => 'Donations welcome'], 'Donations welcome'],
    'unknown without text' => [['price_type' => PriceType::Unknown, 'price_text' => null], null],
]);

it('links an http(s) source by host', function (string $url, string $host) {
    $card = onlyCard(['source_url' => $url, 'organiser_contact' => null]);

    expect($card)
        ->toContain('href="'.e($url).'"')
        ->toContain('rel="nofollow noopener"')
        ->toContain('>'.$host.'</a>');
})->with([
    ['https://example.org/events/1', 'example.org'],
    ['http://www.example.org/whats-on', 'example.org'],
]);

it('never renders a non-http source_url as a link', function () {
    $card = onlyCard(['source_url' => 'javascript:alert(1)', 'organiser_contact' => null]);

    expect($card)->not->toContain('javascript:')->not->toContain('Source:');
});

it('falls back to a mailto contact when there is no source link', function () {
    $card = onlyCard(['source_url' => null, 'organiser_contact' => 'hello@example.org']);

    expect($card)->toContain('<a href="mailto:hello@example.org">hello@example.org</a>');
});

it('shows a non-email contact as plain text', function () {
    $card = onlyCard(['source_url' => null, 'organiser_contact' => '01953 123456']);

    expect($card)->toContain('Contact: 01953 123456')->not->toContain('mailto:')->not->toContain('tel:');
});

it('shows only the source link when both source and contact are set', function () {
    $card = onlyCard(['source_url' => 'https://example.org/events/1', 'organiser_contact' => 'hello@example.org']);

    expect($card)->toContain('example.org</a>')->not->toContain('hello@example.org')->not->toContain('Contact:');
});

it('renders no empty lines for missing optional fields', function () {
    $card = onlyCard([
        'description' => 'Plain text.',
        'venue_id' => Venue::factory()->create(['name' => 'Town Hall'])->id,
        'price_type' => PriceType::Unknown,
        'price_text' => null,
        'organiser_name' => null,
        'source_url' => null,
        'organiser_contact' => null,
        'family_friendly' => false,
    ]);

    expect($card)
        ->not->toContain('class="price"')
        ->not->toContain('By ')
        ->not->toContain('Source:')
        ->not->toContain('Contact:')
        ->not->toContain('Family-friendly')
        ->not->toMatch('#<p[^>]*>\s*</p>#');
});

it('shows a 140-character description in full', function () {
    $text = str_repeat('a', 140);

    expect(excerptOf(onlyCard(['description' => $text])))->toBe($text);
});

it('shortens a 141-character description at a word boundary with an ellipsis', function () {
    $text = implode(' ', array_fill(0, 14, 'abcdefghi')).' x'; // 141 characters
    expect(mb_strlen($text))->toBe(141);

    $excerpt = excerptOf(onlyCard(['description' => $text]));

    expect($excerpt)->toBeString()->toEndWith('…')
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(141)
        ->and($excerpt)->toBe(implode(' ', array_fill(0, 14, 'abcdefghi')).'…');
});

it('collapses newlines and whitespace runs in the excerpt', function () {
    expect(excerptOf(onlyCard(['description' => "Line one.\n\nLine   two.\r\n\tEnd."])))
        ->toBe('Line one. Line two. End.');
});

it('keeps the excerpt valid UTF-8 when the cut lands next to multi-byte characters', function () {
    $excerpt = excerptOf(onlyCard(['description' => str_repeat('a', 139).'é🎉🎉']));

    expect($excerpt)->toBeString()
        ->and(mb_check_encoding($excerpt, 'UTF-8'))->toBeTrue()
        ->and($excerpt)->toEndWith('…')
        ->and(mb_strlen($excerpt))->toBeLessThanOrEqual(141);
});

it('escapes card text', function () {
    $card = onlyCard(['title' => '<b>x</b>', 'description' => '<i>y</i> & more']);

    expect($card)
        ->toContain('&lt;b&gt;x&lt;/b&gt;')
        ->not->toContain('<b>x</b>')
        ->not->toContain('<i>y</i>');
});

it('shows exactly one Cancelled badge when both event and occurrence are cancelled', function () {
    $card = onlyCard(['title' => 'Bonfire', 'status' => 'cancelled'], status: 'cancelled');

    expect(substr_count($card, 'Cancelled'))->toBe(1);
});

it('shows no Cancelled badge on a scheduled occurrence of a published event', function () {
    expect(onlyCard(['title' => 'Bonfire']))->not->toContain('Cancelled');
});

it('renders cards with an identical start in id order', function () {
    makeOccurrence(['title' => 'Zed Talk'], '2026-10-31 19:30');
    makeOccurrence(['title' => 'Abe Talk'], '2026-10-31 19:30');

    get('/')->assertOk()->assertSeeInOrder(['Zed Talk', 'Abe Talk']);
});

it('renders occurrences of one event on different days as separate cards under their own headings', function () {
    $first = makeOccurrence(['title' => 'Weekly Yoga'], '2026-10-30 10:00');
    EventOccurrence::forceCreate([
        'event_id' => $first->event_id,
        'recurrence_key' => '2026-11-06',
        'starts_at' => CarbonImmutable::parse('2026-11-06 10:00', 'Europe/London')->utc(),
        'expires_at' => CarbonImmutable::parse('2026-11-06 13:00', 'Europe/London')->utc(),
        'status' => 'scheduled',
    ]);

    $response = get('/')->assertOk()
        ->assertSeeInOrder(['Friday 30 October', 'Weekly Yoga', 'Friday 6 November', 'Weekly Yoga']);
    expect(cardsIn($response))->toHaveCount(2);
});

it('shows a timed occurrence that ends exactly at midnight as a single day', function () {
    makeOccurrence(
        ['title' => 'Halloween Disco', 'start_date' => '2026-10-31', 'start_time' => '19:00:00', 'end_time' => '00:00:00'],
        '2026-10-31 19:00',
        '2026-11-01 00:00',
    );
    $cards = cardsIn(get('/')->assertOk());

    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toContain('>Sat 31 Oct, 7pm</time>')
        ->and($cards[0])->not->toContain('until');
});

it('still shows an overnight occurrence that ends after midnight as running into the next day', function () {
    makeOccurrence(
        ['title' => 'Late Gig', 'start_date' => '2026-10-31', 'start_time' => '22:00:00', 'end_time' => '00:30:00'],
        '2026-10-31 22:00',
        '2026-11-01 00:30',
    );
    $cards = cardsIn(get('/')->assertOk());

    expect($cards)->toHaveCount(1)
        ->and($cards[0])->toContain('Sat 31 Oct, 10pm until Sun 1 Nov');
});

it('shows London wall-clock hours and minutes only', function (string $start, string $label) {
    expect(onlyCard(['title' => 'Timed'], $start))->toContain('>'.$label.'</time>');
})->with([
    ['2026-10-31 19:00', 'Sat 31 Oct, 7pm'],
    ['2026-10-31 19:30', 'Sat 31 Oct, 7:30pm'],
    ['2026-10-31 12:00', 'Sat 31 Oct, 12pm'],
    ['2026-10-31 00:30', 'Sat 31 Oct, 12:30am'],
]);
