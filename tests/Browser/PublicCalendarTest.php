<?php

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use Tests\Browser\UatFixtures as Uat;

/*
 * Browser UAT F1 (public look) and F2 (filters without JavaScript),
 * docs/uat/browser-uat.md. Frozen on Thu 29 Oct 2026, after the clock change.
 */
beforeEach(function () {
    freezeLondon('2026-10-29 10:00');
    Uat::seedCategories();
    $admin = Uat::admin();

    Uat::publish($admin, ['title' => 'UAT Coffee Morning', 'startDate' => '2026-10-29', 'startTime' => '14:00:00']);
    Uat::publish($admin, ['title' => 'UAT Bingo', 'startDate' => '2026-10-30', 'priceType' => PriceType::Paid, 'priceText' => '£3']);
    Uat::publish($admin, ['title' => 'UAT Family Fun Day', 'startDate' => '2026-10-31', 'startTime' => '11:00:00', 'priceType' => PriceType::Free, 'familyFriendly' => true]);
    Uat::publish($admin, ['title' => 'UAT Craft Fair', 'categoryId' => Uat::category('market')->id, 'startDate' => '2026-11-01', 'startTime' => '10:00:00', 'priceType' => PriceType::Free]);

    $concert = Uat::publish($admin, ['title' => 'UAT Concert', 'categoryId' => Uat::category('music')->id, 'startDate' => '2026-11-07', 'startTime' => '19:00:00']);
    app(ChangeOccurrenceStatus::class)($concert->occurrences()->sole(), OccurrenceStatus::Cancelled, $admin);
});

/**
 * Layout facts measured in the browser (F1 step 3).
 *
 * @return array{overflow: bool, scripts: int, bg: string, firstFocusable: string, smallChips: list<string>}
 */
function measurePublicPage(mixed $page): array
{
    return $page->script(<<<'JS'
        () => ({
            overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            scripts: document.scripts.length,
            bg: getComputedStyle(document.body).backgroundColor,
            firstFocusable: document.querySelector('a[href], button, input, select, textarea, summary, [tabindex]').textContent.trim(),
            smallChips: [...document.querySelectorAll('nav[aria-label="Filter events"] a, nav[aria-label="Filter events"] summary')]
                .filter((el) => el.offsetParent !== null)
                .filter((el) => { const r = el.getBoundingClientRect(); return r.width < 24 || r.height < 24; })
                .map((el) => el.textContent.trim()),
        })
    JS);
}

it('F1: shows the branded calendar on a desktop with no scripts', function () {
    $page = visit('/')->resize(1366, 900);

    $page->assertTitle("What's on | What's On Attleborough")
        ->assertSee("See what's on in Attleborough in one place.")
        ->assertSeeIn('#day-2026-10-29', 'Today')
        ->assertPresent('#day-2026-10-29 time[datetime="2026-10-29"]')
        ->assertSeeIn('#day-2026-10-30', 'Tomorrow')
        ->assertSeeIn('#day-2026-10-31', 'Saturday 31 October')
        ->assertSeeIn('article:has-text("UAT Concert")', 'Cancelled')
        ->assertDontSeeIn('article:has-text("UAT Bingo")', 'Cancelled')
        ->assertAttribute(Uat::role('link', 'All upcoming'), 'aria-current', 'true')
        ->assertPresent('a[aria-current="true"] svg')
        ->assertNotPresent('meta[name="robots"]')
        ->assertNoJavaScriptErrors();

    expect(measurePublicPage($page))
        ->overflow->toBeFalse()
        ->scripts->toBe(0)
        ->firstFocusable->toBe('Skip to main content')
        ->smallChips->toBe([]);
});

it('F1: fits a phone in light and dark mode', function () {
    $light = visit('/')->inLightMode()->resize(375, 812);
    $lightFacts = measurePublicPage($light);

    $dark = visit('/')->inDarkMode()->resize(375, 812);
    $darkFacts = measurePublicPage($dark);

    expect($lightFacts)->overflow->toBeFalse()->scripts->toBe(0)->smallChips->toBe([])
        ->and($darkFacts)->overflow->toBeFalse()->scripts->toBe(0)->smallChips->toBe([])
        ->and($darkFacts['bg'])->not->toBe($lightFacts['bg']);

    // Dark mode really is dark: every RGB channel of the page background is low.
    preg_match_all('/\d+/', $darkFacts['bg'], $channels);
    expect(array_map('intval', array_slice($channels[0], 0, 3)))->each->toBeLessThan(80);

    $dark->assertSee('UAT Family Fun Day')->assertNoJavaScriptErrors();
});

it('F2: combines and removes filters with plain links', function () {
    $page = visit('/');

    $page->click(Uat::role('link', 'This weekend'))->waitForEvent('load')
        ->assertPathIs('/')
        ->assertQueryStringHas('when', 'weekend')
        ->assertCount('main >> article', 2)
        ->assertAttribute('meta[name="robots"]', 'content', 'noindex, follow')
        ->assertTitleContains('This weekend');

    // Category and Show only sit inside the native "More filters" <details>.
    $page->click('More filters')
        ->click(Uat::role('link', 'Community'))->waitForEvent('load')
        ->assertQueryStringHas('category', 'community')
        ->assertCount('main >> article', 1)
        ->click(Uat::role('link', 'Free'))->waitForEvent('load')
        ->assertQueryStringHas('free', '1')
        ->click(Uat::role('link', 'Family-friendly'))->waitForEvent('load')
        ->assertQueryStringHas('family', '1')
        ->assertQueryStringHas('when', 'weekend')
        ->assertQueryStringHas('category', 'community')
        ->assertCount('main >> article', 1)
        ->assertSee('UAT Family Fun Day')
        ->assertSeeIn('.filter-summary', 'Showing: This weekend · Community · Free · Family-friendly')
        ->assertScript('document.scripts.length', 0);

    $page->click(Uat::role('link', 'Today'))->waitForEvent('load')
        ->assertQueryStringHas('when', 'today')
        ->assertCount('main >> article', 0)
        ->assertSee('No events match these filters. Try removing one, or')
        ->assertAttribute(Uat::role('link', 'see everything coming up.'), 'href', '/');

    $page->click(Uat::role('link', 'This week'))->waitForEvent('load')
        ->assertQueryStringHas('when', 'week')
        ->assertCount('main >> article', 1);

    $page->click(Uat::role('link', 'All upcoming'))->waitForEvent('load')
        ->assertQueryStringMissing('when')
        ->assertQueryStringHas('category', 'community')
        ->assertCount('main >> article', 1);

    // Clicking an active chip removes only that filter.
    $page->click(Uat::role('link', 'Family-friendly'))->waitForEvent('load')
        ->assertQueryStringMissing('family')
        ->assertQueryStringHas('free', '1')
        ->assertQueryStringHas('category', 'community')
        ->assertCount('main >> article', 1)
        ->assertAttribute('meta[name="robots"]', 'content', 'noindex, follow')
        ->assertScript('document.scripts.length', 0)
        ->assertNoJavaScriptErrors();
});
