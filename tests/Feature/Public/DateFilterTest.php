<?php

use Illuminate\Testing\TestResponse;

use function Pest\Laravel\get;

/*
 * CAL-02 date filters on the public list, with CAL-01 filter-aware
 * grouping and D-05 empty states. Fixtures are London wall-clock times.
 */

/**
 * Titles of the cards on a page, in order.
 *
 * @return list<string>
 */
function cardTitles(TestResponse $response): array
{
    return array_map(function (string $card): string {
        preg_match('#<h3[^>]*>\s*(.*?)\s*(<|$)#s', $card, $m);

        return html_entity_decode($m[1] ?? '');
    }, cardsIn($response));
}

it('lists only occurrences overlapping today', function () {
    freezeLondon('2026-10-28 12:00');
    makeOccurrence(['title' => 'Lunch Club'], '2026-10-28 10:00', '2026-10-28 14:00');
    makeOccurrence(['title' => 'Breakfast Talk'], '2026-10-28 08:00', '2026-10-28 11:00');
    makeOccurrence(['title' => 'Midnight Walk'], '2026-10-29 00:00');

    expect(cardTitles(get('/?when=today')->assertOk()))->toBe(['Lunch Club']);
});

it('applies the weekend window edges exactly (half-open, consistent with expiry)', function () {
    freezeLondon('2026-10-28 12:00');
    // All-day Friday: expires at Sat 00:00 London, so not in the weekend.
    makeOccurrence(['title' => 'Friday Fete', 'all_day' => true], '2026-10-30 00:00', '2026-10-31 00:00');
    makeOccurrence(['title' => 'Midnight Start'], '2026-10-31 00:00');
    makeOccurrence(['title' => 'Monday Midnight'], '2026-11-02 00:00');
    // Timed Fri 23:00 with no end expires Sat 02:00, so it overlaps the weekend
    // (RESEARCH Pattern 5 edge: acceptable and consistent with expiry).
    makeOccurrence(['title' => 'Late Gig'], '2026-10-30 23:00');

    expect(cardTitles(get('/?when=weekend')->assertOk()))->toBe(['Late Gig', 'Midnight Start']);
});

it('limits This week on Sunday 23:30 to what has not yet expired today', function () {
    freezeLondon('2026-11-01 23:30');
    makeOccurrence(['title' => 'Evening Service'], '2026-11-01 18:00'); // expired 21:00
    makeOccurrence(['title' => 'Late Film'], '2026-11-01 21:30');      // expires 00:30
    makeOccurrence(['title' => 'Monday Yoga'], '2026-11-02 10:00');

    expect(cardTitles(get('/?when=week')->assertOk()))->toBe(['Late Film']);
});

it('runs This week from Monday to the exclusive next Monday 00:00', function () {
    freezeLondon('2026-10-26 09:00');
    makeOccurrence(['title' => 'Sunday Late'], '2026-11-01 23:00');
    makeOccurrence(['title' => 'Next Monday'], '2026-11-02 00:00');

    expect(cardTitles(get('/?when=week')->assertOk()))->toBe(['Sunday Late']);
});

it('shows a multi-day event in every window it overlaps under the right heading (viewed Wednesday)', function () {
    freezeLondon('2026-10-28 12:00');
    makeOccurrence(['title' => 'Harvest Fair', 'all_day' => true], '2026-10-30 00:00', '2026-11-02 00:00');

    get('/?when=weekend')->assertOk()->assertSeeInOrder(['Saturday 31 October', 'Harvest Fair']);
    get('/?when=week')->assertOk()->assertSeeInOrder(['Friday 30 October', 'Harvest Fair']);
    expect(cardTitles(get('/?when=today')))->toBe([]);
});

it('shows a multi-day event under Today in every window when viewed on its Saturday', function (string $when) {
    freezeLondon('2026-10-31 09:00');
    makeOccurrence(['title' => 'Harvest Fair', 'all_day' => true], '2026-10-30 00:00', '2026-11-02 00:00');

    get('/?when='.$when)->assertOk()->assertSeeInOrder(['Today', 'Harvest Fair']);
})->with(['today', 'weekend', 'week']);

it('lists an all-day weekend event across the October clock change in weekend and week but not today', function () {
    freezeLondon('2026-10-23 15:00');
    makeOccurrence(['title' => 'Apple Weekend', 'all_day' => true], '2026-10-24 00:00', '2026-10-26 00:00');

    expect(cardTitles(get('/?when=weekend')))->toBe(['Apple Weekend'])
        ->and(cardTitles(get('/?when=week')))->toBe(['Apple Weekend'])
        ->and(cardTitles(get('/?when=today')))->toBe([]);
});

it('keeps a cancelled occurrence listed with a Cancelled badge in every window until it expires', function (string $when) {
    freezeLondon('2026-10-31 09:00');
    makeOccurrence(['title' => 'Bonfire'], '2026-10-31 19:00', status: 'cancelled');

    $response = get('/?when='.$when)->assertOk();
    expect(cardTitles($response))->toBe(['Bonfire'])
        ->and(cardsIn($response)[0])->toContain('Cancelled');

    freezeLondon('2026-10-31 22:00'); // expires_at = 22:00, strict
    expect(cardTitles(get('/?when='.$when)))->toBe([]);
})->with(['today', 'weekend', 'week']);

it('keeps an occurrence of a cancelled event listed with a Cancelled badge in every window', function (string $when) {
    freezeLondon('2026-10-31 09:00');
    makeOccurrence(['title' => 'Bonfire', 'status' => 'cancelled'], '2026-10-31 19:00');

    $response = get('/?when='.$when)->assertOk();
    expect(cardTitles($response))->toBe(['Bonfire'])
        ->and(cardsIn($response)[0])->toContain('Cancelled');
})->with(['today', 'weekend', 'week']);

it('never shows hidden occurrences or occurrences of hidden events in any window', function (string $uri) {
    freezeLondon('2026-10-31 09:00');
    makeOccurrence(['title' => 'Hidden Occurrence'], '2026-10-31 19:00', status: 'hidden');
    makeOccurrence(['title' => 'Hidden Event', 'status' => 'hidden'], '2026-10-31 20:00');
    makeOccurrence(['title' => 'Visible Event'], '2026-10-31 21:00');

    get($uri)->assertOk()
        ->assertDontSee('Hidden Occurrence')
        ->assertDontSee('Hidden Event')
        ->assertSee('Visible Event');
})->with(['/', '/?when=today', '/?when=weekend', '/?when=week']);

it('orders a window by ongoing first, then starts_at, then id', function () {
    freezeLondon('2026-10-28 12:00');
    makeOccurrence(['title' => 'Thursday Talk'], '2026-10-29 10:00');
    makeOccurrence(['title' => 'Zebra Quiz'], '2026-10-28 18:00');
    makeOccurrence(['title' => 'Apple Bingo'], '2026-10-28 18:00');
    makeOccurrence(['title' => 'Half Term Camp', 'all_day' => true], '2026-10-26 00:00', '2026-10-31 00:00');

    $response = get('/?when=week')->assertOk();

    expect(cardTitles($response))->toBe(['Half Term Camp', 'Zebra Quiz', 'Apple Bingo', 'Thursday Talk']);
    $response->assertSeeInOrder(['Today', 'Half Term Camp', 'Zebra Quiz', 'Apple Bingo', 'Tomorrow', 'Thursday Talk']);
});

it('walks every week occurrence exactly once through More events, keeping when=week', function () {
    config(['calendar.page_size' => 2]);
    freezeLondon('2026-10-26 09:00');
    $titles = ['Mon Run', 'Tue Choir', 'Wed Quiz', 'Thu Darts', 'Sun Walk'];
    foreach (['2026-10-26 18:00', '2026-10-27 18:00', '2026-10-28 18:00', '2026-10-29 18:00', '2026-11-01 10:00'] as $i => $start) {
        makeOccurrence(['title' => $titles[$i]], $start);
    }
    makeOccurrence(['title' => 'Next Week'], '2026-11-02 10:00');

    $seen = [];
    $uri = '/?when=week';
    $pages = 0;
    while ($uri !== null && $pages < 10) {
        $response = get($uri)->assertOk();
        $seen = [...$seen, ...cardTitles($response)];
        $pages++;

        $uri = null;
        if (preg_match('#<a href="([^"]+)" rel="next"[^>]*>More events</a>#', (string) $response->getContent(), $m)) {
            $uri = html_entity_decode($m[1]);
            expect($uri)->toContain('when=week');
        }
    }

    expect($seen)->toBe($titles)->and($pages)->toBe(3);
});

it('adds the year to headings outside the current London year inside time datetime', function () {
    freezeLondon('2026-12-28 10:00');
    makeOccurrence(['title' => 'New Year Swim'], '2027-01-01 11:00');

    get('/')->assertOk()
        ->assertSee('<time datetime="2027-01-01">Friday 1 January 2027</time>', false);
});

it('shows the Today empty state linking to the weekend chip', function () {
    freezeLondon('2026-10-28 12:00');
    makeOccurrence(['title' => 'Saturday Market'], '2026-10-31 10:00');

    $response = get('/?when=today')->assertOk()
        ->assertSeeText('Nothing on today. Why not check the weekend?', false);
    assertLink($response, route('calendar', ['when' => 'weekend'], absolute: false), 'Why not check the weekend?', false);
    assertLink($response, route('calendar', ['when' => 'today'], absolute: false), 'Today', true);
});

it('shows the weekend and week empty states linking to everything coming up', function (string $when, string $message) {
    freezeLondon('2026-10-28 12:00');
    makeOccurrence(['title' => 'Far Future'], '2026-11-20 10:00');

    $response = get('/?when='.$when)->assertOk()->assertSeeText($message, false);
    expect(cardTitles($response))->toBe([]);
    assertLink($response, route('calendar', absolute: false), 'See everything coming up.');
})->with([
    ['weekend', 'Nothing on this weekend yet. See everything coming up.'],
    ['week', 'Nothing else on this week. See everything coming up.'],
]);

it('shows the end-of-list state for a page past the end, linking to page 1 with the same filters', function () {
    freezeLondon('2026-10-28 12:00');
    makeOccurrence(['title' => 'Saturday Market'], '2026-10-31 10:00');

    $response = get('/?when=weekend&page=5')->assertOk()
        ->assertSeeText("You've reached the end of the list.", false)
        ->assertDontSee('Nothing on this weekend yet');
    assertLink($response, route('calendar', ['when' => 'weekend'], absolute: false), 'Back to the first page');
});

it('keeps the 01-01 empty state when nothing is published and no filter is set', function () {
    get('/')->assertOk()->assertSee('Nothing on yet. Check back soon.');
});

it('shows a Showing summary and page title for the active filter only', function () {
    freezeLondon('2026-10-28 12:00');

    get('/?when=weekend')->assertOk()
        ->assertSeeText('Showing: This weekend')
        ->assertSee('<title>This weekend |', false);

    get('/')->assertOk()->assertDontSeeText('Showing:');
});
