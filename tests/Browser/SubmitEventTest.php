<?php

use App\Enums\EventOrigin;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use Tests\Browser\UatFixtures as Uat;

/*
 * Browser UAT F11 (submit an event, public), docs/uat/browser-uat.md.
 * Offline: no Turnstile site key in tests, so neither the widget nor
 * Cloudflare's script is rendered, and the verifier is faked.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    $this->venue = Uat::venue();
    Uat::fakeTurnstile();
});

/**
 * Fill every required field of the public form (known venue unless the
 * caller changes it), leaving the date out when $date is null.
 */
function fillSubmission(mixed $page, string $title, ?string $date = '2026-10-31'): mixed
{
    $page->fill(Uat::label('Name of the event'), $title)
        ->fill(Uat::label('Description'), 'Carve a lantern, then hot soup in the hall. Bring a spoon.')
        ->select(Uat::label('Category'), 'Community');

    if ($date !== null) {
        $page->fill('#start_date', $date);
    }

    return $page->fill(Uat::label('Start time'), '18:30')
        ->fill(Uat::label('End time (optional)'), '20:30')
        ->select(Uat::label('Venue'), Uat::venue()->name)
        ->click('#price_type_free')
        ->fill(Uat::label('Your name or group'), 'UAT Parish Group')
        ->fill(Uat::label('Email address'), 'parish@example.test')
        ->check('#consent');
}

/**
 * Send the form once the timing token is old enough (3-second minimum).
 */
function sendForReview(mixed $page): mixed
{
    test()->travel(5)->seconds();

    return $page->click(Uat::role('button', 'Send for review'));
}

it('F11: sends an event for review, shows the reference and keeps it off the calendar', function () {
    $page = visit('/submit');

    $page->assertTitleContains('Add your event')
        ->assertNotPresent('.cf-turnstile')
        ->assertNotPresent('script[src*="challenges.cloudflare.com"]');

    fillSubmission($page, 'UAT Lantern Evening');

    sendForReview($page)
        ->waitForText('Thanks for sending your event')
        ->assertPathIs('/submit/thanks')
        ->assertSee('Your reference: WOA-');

    $draft = EventDraft::query()->sole();
    expect($draft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->origin)->toBe(EventOrigin::Submission)
        ->and($draft->title)->toBe('UAT Lantern Evening')
        ->and($draft->venue_id)->toBe($this->venue->id);

    $page->assertSee($draft->reference);

    $page->navigate('/')
        ->assertDontSee('UAT Lantern Evening')
        ->assertNoJavaScriptErrors();
});

it('F11: shows the "Somewhere else" fields until a known venue is picked', function () {
    $page = visit('/submit');

    // Visible before any choice, so the form also works without JavaScript.
    $page->assertVisible(Uat::label('Name of the place'))
        ->assertVisible('#venue_address');

    fillSubmission($page, 'UAT Barn Dance');
    $page->assertMissing(Uat::label('Name of the place'));

    $page->select(Uat::label('Venue'), 'Somewhere else')
        ->assertVisible(Uat::label('Name of the place'))
        ->fill(Uat::label('Name of the place'), 'The Old Barn')
        ->fill('#venue_address', 'Mill Lane, Attleborough')
        ->fill(Uat::label('Postcode (optional)'), 'NR17 2AA');

    sendForReview($page)
        ->waitForText('Thanks for sending your event')
        ->assertNoJavaScriptErrors();

    $draft = EventDraft::query()->sole();
    expect($draft->venue_id)->toBeNull()
        ->and($draft->venue_name)->toBe('The Old Barn')
        ->and($draft->venue_address)->toBe('Mill Lane, Attleborough');
});

it('F11: a missing date shows the error summary, keeps the typed values and saves nothing', function () {
    $page = visit('/submit');

    fillSubmission($page, 'UAT Lantern Evening', date: null);

    sendForReview($page)
        ->waitForText('Please check these')
        ->assertPathIs('/submit')
        ->assertPresent('[data-error-summary] a[href="#start_date"]')
        ->assertAttribute('#start_date', 'aria-invalid', 'true')
        ->assertValue(Uat::label('Name of the event'), 'UAT Lantern Evening')
        ->assertValue(Uat::label('Your name or group'), 'UAT Parish Group');

    // submit.js moves focus to the summary so keyboard users start there.
    Uat::waitUntil($page, "document.activeElement?.hasAttribute('data-error-summary')");

    $page->assertNoJavaScriptErrors();

    expect(EventDraft::query()->count())->toBe(0);
});

it('F11: tells people without JavaScript how else to send their event', function () {
    visit('/submit')
        ->assertSourceHas('<noscript>')
        ->assertSourceHas('Our spam check needs JavaScript')
        ->assertNoJavaScriptErrors();
});
