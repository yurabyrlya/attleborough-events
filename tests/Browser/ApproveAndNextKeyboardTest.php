<?php

use App\Enums\QualityBand;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * REV-10 / D-10 in a real browser: the review page's single-key shortcuts,
 * their guards (typing in a field, an open dialog) and the remembered
 * "Keyboard shortcuts" off switch (WCAG 2.1.4). A stray "a" typed into the
 * reject note must never publish anything (T-02-39).
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    actingAs(Uat::admin());

    $draft = fn (string $title, string $date): EventDraft => EventDraft::factory()
        ->scored(QualityBand::High, 95)
        ->create([
            'title' => $title,
            'start_date' => $date,
            'venue_id' => Uat::venue()->id,
            'category_id' => Uat::category('community')->id,
        ]);

    $this->draftA = $draft('UAT Draft A', '2026-10-22');
    $this->draftB = $draft('UAT Draft B', '2026-10-23');
    $this->draftC = $draft('UAT Draft C', '2026-10-24');
});

/** A CSS selector for the page itself (a bare "body" would match text). */
const PAGE_BODY = 'html > body';

function reviewPath(EventDraft $draft): string
{
    return '/admin/event-drafts/'.$draft->getRouteKey().'/review';
}

/**
 * Open a draft's review page and wait until Alpine has wired the toggle.
 */
function openReviewPage(EventDraft $draft, mixed $page = null): mixed
{
    $page = $page === null ? visit(reviewPath($draft)) : $page->navigate(reviewPath($draft));
    $page->waitForText($draft->title);

    return Uat::waitUntil($page, "document.getElementById('woa-shortcuts-toggle')?._x_model !== undefined");
}

function dialogOpen(): string
{
    return "document.querySelector('.fi-modal-open') !== null";
}

it('approves with A then Enter and opens the next draft', function () {
    $page = openReviewPage($this->draftA);

    $page->keys(PAGE_BODY, 'a')
        ->waitForText('Publish this event?')
        ->assertPresent(Uat::role('button', 'Keep as is'));

    // "Publish" has the focus, so Enter publishes.
    Uat::waitUntil($page, "document.activeElement?.closest('.fi-modal-open') && document.activeElement.textContent.trim() === 'Publish'");

    $page->keys(':focus', 'Enter')
        ->waitForText('UAT Draft B')
        ->assertPathIs(reviewPath($this->draftB))
        ->assertNoJavaScriptErrors();

    expect($this->draftA->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($this->draftB->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('ignores keys typed into the reject note and while a dialog is open', function () {
    $page = openReviewPage($this->draftB);

    $page->keys(PAGE_BODY, 'r')
        ->waitForText('Reject this draft?');

    $page->keys(Uat::label('Note'), ['a', 's', 'k'])
        ->wait(0.5)
        ->assertDontSee('Publish this event?')
        ->assertPathIs(reviewPath($this->draftB));

    expect($page->script("() => document.querySelector('textarea')?.value"))->toBe('ask');

    $page->keys(Uat::label('Note'), 'Escape');
    Uat::waitUntil($page, '! ('.dialogOpen().')');

    // No dialog open, but typing in a field (the panel search, the
    // shortcuts checkbox) still triggers nothing.
    $page->keys('input[type=search]', ['a', 's', 'k'])
        ->wait(0.5)
        ->assertDontSee('Publish this event?')
        ->assertPathIs(reviewPath($this->draftB));

    $page->keys('#woa-shortcuts-toggle', ['a', 's'])
        ->wait(0.5)
        ->assertDontSee('Publish this event?')
        ->assertPathIs(reviewPath($this->draftB))
        ->assertNoJavaScriptErrors();

    expect($this->draftB->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('opens the shortcuts help with ?', function () {
    $page = openReviewPage($this->draftA);

    $page->keys(PAGE_BODY, '?')
        ->waitForText('Shortcuts are off while you type in a field or a dialog is open.')
        ->assertSee('Approve & next')
        ->assertSee('Show this help');

    $page->keys(PAGE_BODY, 'Escape');
    Uat::waitUntil($page, '! ('.dialogOpen().')');

    $page->assertNoJavaScriptErrors();
});

it('goes back with K and forward with J', function () {
    $page = openReviewPage($this->draftB);

    $page->keys(PAGE_BODY, 'k')
        ->waitForText('UAT Draft A')
        ->assertPathIs(reviewPath($this->draftA));

    $page = openReviewPage($this->draftA, $page);

    $page->keys(PAGE_BODY, 'j')
        ->waitForText('UAT Draft B')
        ->assertPathIs(reviewPath($this->draftB))
        ->assertNoJavaScriptErrors();
});

it('turns the shortcuts off and remembers it, then back on', function () {
    $page = openReviewPage($this->draftB);

    $page->uncheck('#woa-shortcuts-toggle');
    expect($page->script("() => localStorage.getItem('woa-shortcuts')"))->toBe('off');

    $page = openReviewPage($this->draftB, $page);
    $page->assertNotChecked('#woa-shortcuts-toggle');

    $page->keys(PAGE_BODY, 's')
        ->wait(0.75)
        ->assertPathIs(reviewPath($this->draftB));

    $page->check('#woa-shortcuts-toggle');
    expect($page->script("() => localStorage.getItem('woa-shortcuts')"))->toBe('on');
    $page->script('() => document.activeElement?.blur()');

    $page->keys(PAGE_BODY, 's')
        ->waitForText('UAT Draft C')
        ->assertPathIs(reviewPath($this->draftC))
        ->assertNoJavaScriptErrors();

    expect($this->draftB->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('says why A cannot approve a draft without a venue', function () {
    $blocked = EventDraft::factory()->freeTextVenue()->create([
        'title' => 'UAT Blocked Draft',
        'start_date' => '2026-10-21',
        'category_id' => Uat::category('community')->id,
    ]);

    $page = openReviewPage($blocked);

    $page->keys(PAGE_BODY, 'a')
        ->waitForText("Can't approve yet")
        ->assertSee('Pick or create a venue.')
        ->assertDontSee('Publish this event?')
        ->assertPathIs(reviewPath($blocked))
        ->assertNoJavaScriptErrors();

    expect($blocked->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});
