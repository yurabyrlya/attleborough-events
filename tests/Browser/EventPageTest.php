<?php

use App\Enums\OccurrenceStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use Tests\Browser\UatFixtures as Uat;

/*
 * Browser UAT F9 (event page, add to calendar and sharing),
 * docs/uat/browser-uat.md. The .ics download itself is covered by
 * tests/Feature/Public/EventCalendarAndShareTest.php.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    $admin = Uat::admin();

    $this->event = Uat::quizNight($admin);
    $cancelled = $this->event->occurrences()->where('recurrence_key', '2026-11-27')->sole();
    app(ChangeOccurrenceStatus::class)($cancelled, OccurrenceStatus::Cancelled, $admin);
});

it('F9: shows the event page with calendar links, a cancelled date and the share block', function () {
    $page = visit('/events/'.$this->event->slug)->inLightMode()->resize(375, 812);

    $page->assertSee('← All events')
        ->assertSeeIn('main >> h1', 'UAT Quiz Night')
        ->assertSee('£2 per person')
        ->assertSeeIn('#dates-heading', 'Upcoming dates')
        ->assertSeeIn('#where-heading', 'Where')
        ->assertSee('UAT Town Hall')
        ->assertSeeIn('#about-heading', 'About this event')
        ->assertSeeIn('#who-heading', "Who's running it")
        ->assertSee('Organised by The Griffin')
        ->assertAttribute(Uat::role('link', 'quiz@example.test'), 'href', 'mailto:quiz@example.test')
        ->assertSee('Listed by What\'s On Attleborough.')
        ->assertSeeIn('#share-heading', 'Share this event')
        // Accessible names include the date, so screen reader users can tell the links apart.
        ->assertPresent(Uat::role('link', 'Add to calendar (.ics) for Fri 30 Oct, 7:30pm'))
        ->assertAttributeContains(Uat::role('link', 'Google Calendar for Fri 30 Oct, 7:30pm'), 'href', 'dates=20261030T193000Z%2F20261030T220000Z')
        ->assertSeeIn('li:has(time[datetime^="2026-11-27"])', 'Cancelled')
        ->assertNotPresent('li:has(time[datetime^="2026-11-27"]) a')
        ->assertNotPresent(Uat::role('link', 'Add to calendar (.ics) for Fri 27 Nov, 7:30pm'))
        ->assertPresent(Uat::role('link', 'WhatsApp'))
        ->assertPresent(Uat::role('link', 'Facebook'))
        ->assertPresent(Uat::role('link', 'Email'))
        ->assertAttribute(Uat::role('textbox', 'Link to this event'), 'readonly', '')
        ->assertNoJavaScriptErrors();

    $scripts = $page->script(<<<'JS'
        () => ({
            jsonLd: document.querySelectorAll('script[type="application/ld+json"]').length,
            dates: document.querySelectorAll('ul.dates > li').length,
            external: [...document.scripts].filter((s) => s.src !== '').map((s) => s.src)
                .filter((src) => ! (src.startsWith(location.origin + '/build/assets/share-') && src.endsWith('.js'))),
            shareHiddenCorrectly: document.querySelector('[data-share-native]').hidden === (typeof navigator.share !== 'function'),
        })
    JS);

    expect($scripts)
        ->jsonLd->toBe(min($scripts['dates'], 10))
        ->external->toBe([])
        ->shareHiddenCorrectly->toBeTrue();
});

it('F9: copies the event link and announces it', function () {
    $page = visit('/events/'.$this->event->slug);

    // Headless Chromium has no clipboard permission: record what share.js writes instead.
    $page->script(<<<'JS'
        () => {
            Object.defineProperty(navigator.clipboard, 'writeText', {
                configurable: true,
                value: async (text) => { window.__copied = text; },
            });
        }
    JS);

    $page->click(Uat::role('button', 'Copy link'))
        ->waitForText('Link copied.')
        ->assertSeeIn('[role="status"]', 'Link copied.');

    expect($page->script('() => window.__copied'))->toBe($page->value('#share-url'));
});
