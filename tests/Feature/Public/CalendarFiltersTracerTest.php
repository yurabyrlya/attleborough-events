<?php

use function Pest\Laravel\get;

/*
 * Tracer (CAL-02, CAL-06): a visitor taps "This weekend" on / (a plain
 * anchor) and gets a server-rendered list containing only occurrences that
 * overlap the upcoming Sat 00:00 to Mon 00:00 Europe/London window.
 */

beforeEach(function () {
    freezeLondon('2026-10-28 12:00');

    makeOccurrence(['title' => 'Wednesday Quiz'], '2026-10-28 19:00');
    makeOccurrence(['title' => 'Saturday Market'], '2026-10-31 10:00');
    makeOccurrence(['title' => 'Sunday Concert'], '2026-11-01 14:00');
    makeOccurrence(['title' => 'Monday Club'], '2026-11-02 10:00');
});

it('shows only weekend-overlapping occurrences on /?when=weekend', function () {
    get('/?when=weekend')
        ->assertOk()
        ->assertSee('Saturday Market')
        ->assertSee('Sunday Concert')
        ->assertDontSee('Wednesday Quiz')
        ->assertDontSee('Monday Club');
});

it('marks /?when=weekend noindex with a canonical built from config', function () {
    get('/?when=weekend')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('<link rel="canonical" href="'.rtrim(config('app.url'), '/').'/">', false);
});

it('renders the weekend chip as a plain anchor on / without noindex and lists everything', function () {
    get('/')
        ->assertOk()
        ->assertSee('href="'.e(route('calendar', ['when' => 'weekend'], absolute: false)).'"', false)
        ->assertDontSee('name="robots"', false)
        ->assertSee('Wednesday Quiz')
        ->assertSee('Saturday Market')
        ->assertSee('Sunday Concert')
        ->assertSee('Monday Club');
});

it('marks the active weekend chip and points All upcoming at the bare route', function () {
    get('/?when=weekend')
        ->assertOk()
        ->assertSee('aria-current="true">This weekend</a>', false)
        ->assertSee('<a href="'.e(route('calendar', absolute: false)).'">All upcoming</a>', false);
});

it('renders no form and no script on / and /?when=weekend', function (string $uri) {
    get($uri)
        ->assertOk()
        ->assertDontSee('<form', false)
        ->assertDontSee('<script', false);
})->with(['/', '/?when=weekend']);
