<?php

use App\Enums\PriceType;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

/*
 * CAL-03 category, CAL-04 free, CAL-05 family-friendly, combined with the
 * date filter using AND; CAL-06 safe param handling and chip links.
 */

beforeEach(function () {
    freezeLondon('2026-10-28 12:00');
    $this->music = Category::factory()->create(['name' => 'Music', 'slug' => 'music', 'sort' => 1]);
    $this->family = Category::factory()->create(['name' => 'Family', 'slug' => 'family', 'sort' => 2]);
});

/**
 * @return list<string>
 */
function titlesOn(string $uri): array
{
    $response = get($uri)->assertOk();

    return array_map(function (string $card): string {
        preg_match('#<h3[^>]*>\s*(.*?)\s*(<|$)#s', $card, $m);

        return html_entity_decode($m[1] ?? '');
    }, cardsIn($response));
}

it('filters by category slug', function () {
    makeOccurrence(['title' => 'Jazz Night', 'category_id' => $this->music->id], '2026-10-30 19:30');
    makeOccurrence(['title' => 'Story Time', 'category_id' => $this->family->id], '2026-10-30 10:00');

    expect(titlesOn('/?category=music'))->toBe(['Jazz Night']);
});

it('compares category slugs case-insensitively', function () {
    makeOccurrence(['title' => 'Jazz Night', 'category_id' => $this->music->id], '2026-10-30 19:30');
    makeOccurrence(['title' => 'Story Time', 'category_id' => $this->family->id], '2026-10-30 10:00');

    expect(titlesOn('/?category=MUSIC'))->toBe(['Jazz Night']);
});

it('returns 404 for a category slug that does not exist', function () {
    get('/?category=nonexistent')->assertNotFound();
});

it('returns 404 for a malformed category value without echoing it', function () {
    $response = get('/?category=%3Cb%3E')->assertNotFound();

    expect((string) $response->getContent())
        ->not->toContain('&lt;b&gt;')
        ->not->toContain('<b>');
});

it('rejects malformed slugs with 404 before any category lookup', function (string $raw) {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    get('/?category='.$raw)->assertNotFound();

    expect(array_filter($queries, fn (string $sql) => str_contains($sql, '"slug"')))->toBe([]);
})->with([
    'percent-encoded symbol' => ['music%21'],
    'non-ASCII' => ['m%C3%BCsic'],
    'longer than 64' => [str_repeat('a', 65)],
]);

it('treats an empty category as absent but still noindexes the URL', function () {
    makeOccurrence(['title' => 'Jazz Night', 'category_id' => $this->music->id], '2026-10-30 19:30');
    makeOccurrence(['title' => 'Story Time', 'category_id' => $this->family->id], '2026-10-30 10:00');

    get('/?category=')->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
    expect(titlesOn('/?category='))->toBe(['Story Time', 'Jazz Night']);
});

it('filters Free to price_type free only', function () {
    makeOccurrence(['title' => 'Free Fair', 'price_type' => PriceType::Free], '2026-10-30 10:00');
    makeOccurrence(['title' => 'Says Free', 'price_type' => PriceType::Unknown, 'price_text' => 'Free'], '2026-10-30 11:00');
    makeOccurrence(['title' => 'Paid Gig', 'price_type' => PriceType::Paid], '2026-10-30 12:00');

    expect(titlesOn('/?free=1'))->toBe(['Free Fair']);
});

it('ignores free values other than 1', function () {
    makeOccurrence(['title' => 'Free Fair', 'price_type' => PriceType::Free], '2026-10-30 10:00');
    makeOccurrence(['title' => 'Paid Gig', 'price_type' => PriceType::Paid], '2026-10-30 12:00');

    expect(titlesOn('/?free=yes'))->toBe(['Free Fair', 'Paid Gig']);
});

it('filters family-friendly by the flag, independent of the Family category', function () {
    makeOccurrence(['title' => 'Family Unflagged', 'category_id' => $this->family->id, 'family_friendly' => false], '2026-10-30 10:00');
    makeOccurrence(['title' => 'Music Flagged', 'category_id' => $this->music->id, 'family_friendly' => true], '2026-10-30 11:00');

    expect(titlesOn('/?family=1'))->toBe(['Music Flagged']);
});

it('combines date, category, free and family with AND', function () {
    $all = ['category_id' => $this->music->id, 'price_type' => PriceType::Free, 'family_friendly' => true];
    makeOccurrence(['title' => 'All Four', ...$all], '2026-10-31 10:00');
    makeOccurrence(['title' => 'Not Family', ...$all, 'family_friendly' => false], '2026-10-31 11:00');
    makeOccurrence(['title' => 'Not Free', ...$all, 'price_type' => PriceType::Paid], '2026-10-31 12:00');
    makeOccurrence(['title' => 'Not Music', ...$all, 'category_id' => $this->family->id], '2026-10-31 13:00');
    makeOccurrence(['title' => 'Not Weekend', ...$all], '2026-10-28 19:00');

    expect(titlesOn('/?when=weekend&category=music&free=1&family=1'))->toBe(['All Four']);
});

it('builds chip hrefs that keep other filters, toggle Free off and never carry page', function () {
    $response = get('/?when=weekend&free=1&page=2')->assertOk()
        ->assertSee('href="'.e(route('calendar', ['when' => 'weekend', 'category' => 'music', 'free' => '1'], absolute: false)).'">Music</a>', false)
        ->assertSee('<a href="'.e(route('calendar', ['when' => 'weekend'], absolute: false)).'" aria-current="true">Free</a>', false)
        ->assertSee('<a href="'.e(route('calendar', ['when' => 'weekend', 'free' => '1'], absolute: false)).'" aria-current="true">All categories</a>', false)
        ->assertSee('<a href="'.e(route('calendar', ['when' => 'weekend', 'free' => '1', 'family' => '1'], absolute: false)).'">Family-friendly</a>', false);

    preg_match('#<nav aria-label="Filter events".*?</nav>#s', (string) $response->getContent(), $nav);
    expect($nav[0])->not->toContain('page=');
});

it('replaces the category when another category chip is chosen', function () {
    get('/?category=music')->assertOk()
        ->assertSee('<a href="'.e(route('calendar', ['category' => 'family'], absolute: false)).'">Family</a>', false)
        ->assertSee('aria-current="true">Music</a>', false);
});

it('treats invalid and array-valued params as absent with 200', function (string $query) {
    makeOccurrence(['title' => 'Jazz Night', 'category_id' => $this->music->id], '2026-10-30 19:30');

    get('/?'.$query)->assertOk()
        ->assertSee('Jazz Night')
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
})->with([
    'when=tomorrow',
    'when[]=today',
    'family[]=1',
    'free[]=1',
    'category[]=music',
    'page=abc',
    'when=',
    'free=',
]);

it('noindexes unknown params and never propagates them into links', function () {
    config(['calendar.page_size' => 1]);
    makeOccurrence(['title' => 'One'], '2026-10-30 10:00');
    makeOccurrence(['title' => 'Two'], '2026-10-30 11:00');

    $response = get('/?utm_source=fb&free=yes')->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('rel="next">More events</a>', false);

    expect((string) $response->getContent())->not->toContain('utm_source');
});

it('keeps a cancelled match listed with its badge and never shows a hidden match', function () {
    $all = ['category_id' => $this->music->id, 'price_type' => PriceType::Free, 'family_friendly' => true];
    makeOccurrence(['title' => 'Called Off', ...$all], '2026-10-30 19:00', status: 'cancelled');
    makeOccurrence(['title' => 'Kept Quiet', ...$all], '2026-10-30 20:00', status: 'hidden');

    $response = get('/?category=music&free=1&family=1')->assertOk();

    expect(cardsIn($response))->toHaveCount(1)
        ->and(cardsIn($response)[0])->toContain('Called Off')->toContain('Cancelled');
    $response->assertDontSee('Kept Quiet');
});

it('uses the attribute-filter empty state when a category, Free or Family filter matches nothing', function (string $query) {
    makeOccurrence(['title' => 'Paid Gig', 'price_type' => PriceType::Paid, 'category_id' => $this->family->id], '2026-10-30 12:00');

    get('/?'.$query)->assertOk()
        ->assertSeeText('No events match these filters. Try removing one, or see everything coming up.', false)
        ->assertSee('<a href="'.e(route('calendar', absolute: false)).'">see everything coming up.</a>', false);
})->with(['category=music', 'free=1', 'family=1', 'when=today&free=1']);

it('lists every category chip in sort order with All categories first', function () {
    get('/')->assertOk()->assertSeeInOrder(['All categories', '>Music</a>', '>Family</a>'], false);
});
