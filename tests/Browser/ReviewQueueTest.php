<?php

use App\Enums\QualityBand;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Venue;
use Tests\Browser\UatFixtures as Uat;

use function Pest\Laravel\actingAs;

/*
 * Browser UAT F12 (review queue, edit-and-approve, Approve & next) and F13
 * (bulk approve and bulk reject), docs/uat/browser-uat.md. Drafts are
 * written through the real submission path (Uat::submissionDraft()). The
 * keyboard half of F12 is ApproveAndNextKeyboardTest.php.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    Uat::seedCategories();
    Uat::venue();
    actingAs(Uat::admin());
});

/** The open Filament dialog, for scoping its buttons and fields. */
const OPEN_MODAL = '.fi-modal-open';

function draftReviewPath(EventDraft $draft): string
{
    return '/admin/event-drafts/'.$draft->getRouteKey().'/review';
}

/**
 * Tick a queue row's bulk-selection checkbox.
 */
function selectRow(mixed $page, string $title): mixed
{
    return $page->check('tr:has-text("'.$title.'") >> input[type=checkbox]');
}

/**
 * Open the table's "Bulk actions" menu and pick one.
 */
function bulkAction(mixed $page, string $label): mixed
{
    return $page->click(Uat::role('button', 'Bulk actions'))
        ->click(Uat::role('menuitem', $label));
}

function waitForModalClosed(mixed $page): mixed
{
    return Uat::waitUntil($page, "document.querySelector('".OPEN_MODAL."') === null");
}

it('F12: reviews, edits and approves a submission, then lands on the next draft', function () {
    // Tue 27 Oct: an unknown venue and a repeat in words (Medium: 100 - 20 - 25).
    $barn = Uat::submissionDraft([
        'title' => 'UAT Barn Social',
        'start_date' => '2026-10-27',
        'venue_choice' => 'other',
        'venue_name' => 'The Old Barn',
        'venue_address' => 'Mill Lane, Attleborough',
        'venue_postcode' => 'NR17 2AA',
        'repeats_text' => 'Every Tuesday for four weeks',
    ]);
    $next = Uat::submissionDraft(['title' => 'UAT Village Talk', 'start_date' => '2026-10-29']);

    expect($barn->quality_band)->toBe(QualityBand::Medium);

    $page = visit('/admin');

    // The link's accessible name also holds the pending badge ("Review queue 2").
    $page->assertSeeIn(Uat::roleContaining('link', 'Review queue'), '2')
        ->click(Uat::roleContaining('link', 'Review queue'))
        ->waitForText('UAT Barn Social')
        ->assertPathIs('/admin/event-drafts')
        ->assertSee('UAT Village Talk')
        ->assertSeeIn('tr:has-text("UAT Barn Social")', 'The Old Barn (not confirmed)');

    // Soonest first (D-09): the barn draft is the first row.
    $page->click('tr:has-text("UAT Barn Social") >> text=UAT Barn Social')
        ->waitForText("Venue not recognised: 'The Old Barn'")
        ->assertPathIs(draftReviewPath($barn))
        ->assertSee('Medium')
        ->assertSee("Organiser says it repeats ('Every Tuesday for four weeks') but no repeat rule is set")
        ->assertSee('Why this category')
        ->assertSee('Chosen by organiser')
        ->assertSee('Before you can publish')
        ->assertSee('Pick or create a venue.');

    $page->click(Uat::role('button', 'Raw content'))
        ->waitForText('What the organiser sent')
        ->assertSeeIn(OPEN_MODAL, 'The Old Barn')
        ->click(OPEN_MODAL.' >> button:has-text("Close")');
    waitForModalClosed($page);

    $page->click(Uat::role('link', 'Edit (E)'))
        ->waitForText('Edit draft: UAT Barn Social');

    // The only way a venue is created from a draft (D-05): pre-filled from what the organiser typed.
    Uat::clickUntil($page, Uat::role('button', 'Create new venue'), "document.querySelector('".OPEN_MODAL."') !== null");

    $page->assertValue(OPEN_MODAL.' >> '.Uat::label('Name'), 'The Old Barn')
        ->assertValue(OPEN_MODAL.' >> '.Uat::label('Postcode'), 'NR17 2AA')
        ->click(OPEN_MODAL.' >> '.Uat::role('button', 'Create'));
    waitForModalClosed($page);

    expect(Venue::query()->where('name', 'The Old Barn')->exists())->toBeTrue();

    Uat::waitUntil($page, 'document.getElementById("form.repeats").options.length > 1');
    $page->select('[id="form.repeats"]', 'Weekly on Tuesday')
        ->click(Uat::role('radio', 'After a number of times'))
        ->fill('[id="form.repeat_count"]', '4');

    Uat::settleFields($page, ['form.repeat_count' => '4'])
        ->click(Uat::role('button', 'Save & approve'))
        ->waitForText('UAT Village Talk')
        ->assertPathIs(draftReviewPath($next))
        ->assertNoJavaScriptErrors();

    $barn->refresh();
    expect($barn->review_status)->toBe(ReviewStatus::Approved)
        ->and($barn->venue?->name)->toBe('The Old Barn')
        ->and($next->refresh()->review_status)->toBe(ReviewStatus::PendingReview);

    $page->navigate('/')
        ->assertCount('main >> article:has-text("UAT Barn Social")', 4)
        ->assertSeeIn('section:has(#day-2026-10-27)', 'UAT Barn Social')
        ->assertSeeIn('section:has(#day-2026-11-17)', 'UAT Barn Social')
        ->assertDontSee('UAT Village Talk')
        ->assertNoJavaScriptErrors();
});

it('F13: bulk approve skips problem drafts and names them', function () {
    Uat::submissionDraft(['title' => 'UAT Craft Stall', 'start_date' => '2026-10-28']);

    // Low (100 - 20 - 15 - 10 - 25 = 30): typed venue, no category match, no end time, repeat in words.
    $low = Uat::submissionDraft([
        'title' => 'UAT Mystery Meetup',
        'start_date' => '2026-10-29',
        'category_choice' => 'not_sure',
        'end_time' => null,
        'venue_choice' => 'other',
        'venue_name' => 'Somewhere Odd',
        'venue_address' => 'A field',
        'repeats_text' => 'Most weeks',
    ]);
    expect($low->quality_band)->toBe(QualityBand::Low);

    // Parse errors only come from imported sources (Phase 3); forced here.
    EventDraft::factory()->submission()->withParseErrors(["Couldn't read the date 'next Thurs'"])->create([
        'title' => 'UAT Garbled Post',
        'start_date' => '2026-10-30',
        'venue_id' => Uat::venue()->id,
        'category_id' => Uat::category('community')->id,
    ]);

    $page = visit('/admin/event-drafts')
        ->waitForText('UAT Garbled Post');

    $page->check(Uat::label('Select/deselect all items for bulk actions.'));
    bulkAction($page, 'Approve selected')
        ->waitForText('Approve the selected drafts?')
        ->assertSee('Drafts with problems are skipped and listed afterwards.')
        ->click(OPEN_MODAL.' >> '.Uat::role('button', 'Approve'))
        ->waitForText('Approved 1 of 3. The rest were skipped:')
        ->assertSee('1 skipped: Low quality')
        ->assertSee('1 skipped: has parse errors')
        ->assertNoJavaScriptErrors();

    expect(Event::query()->pluck('title')->all())->toBe(['UAT Craft Stall']);

    $page->navigate('/')
        ->assertSee('UAT Craft Stall')
        ->assertDontSee('UAT Mystery Meetup')
        ->assertDontSee('UAT Garbled Post');
});

it('F13: bulk rejects with a reason, then reopens one', function () {
    Uat::submissionDraft(['title' => 'UAT Cheap Watches', 'start_date' => '2026-10-28']);
    Uat::submissionDraft(['title' => 'UAT Free Prizes', 'start_date' => '2026-10-29']);
    Uat::submissionDraft(['title' => 'UAT Real Event', 'start_date' => '2026-10-30']);

    $page = visit('/admin/event-drafts')
        ->waitForText('UAT Real Event');

    selectRow($page, 'UAT Cheap Watches');
    selectRow($page, 'UAT Free Prizes');
    bulkAction($page, 'Reject selected')
        ->waitForText('Reject the selected drafts?')
        ->select(OPEN_MODAL.' >> '.Uat::label('Reason (optional)'), 'Spam')
        ->click(OPEN_MODAL.' >> '.Uat::role('button', 'Reject drafts'))
        ->waitForText('Drafts rejected');

    expect(EventDraft::query()->where('reject_reason', RejectReason::Spam)->orderBy('title')->pluck('title')->all())
        ->toBe(['UAT Cheap Watches', 'UAT Free Prizes']);

    $page->click(Uat::roleContaining('button', 'Rejected'))
        ->waitForText('UAT Cheap Watches')
        ->assertSee('UAT Free Prizes')
        ->assertDontSee('UAT Real Event');

    $page->click('tr:has-text("UAT Cheap Watches") >> text=UAT Cheap Watches')
        ->waitForText('Review status')
        ->click(Uat::role('button', 'Reopen'))
        ->waitForText('Reopen this draft?')
        ->click(OPEN_MODAL.' >> '.Uat::role('button', 'Reopen'))
        ->waitForText('Draft reopened');

    $page->navigate('/admin/event-drafts')
        ->waitForText('UAT Cheap Watches')
        ->assertSee('UAT Real Event')
        ->assertDontSee('UAT Free Prizes')
        ->assertNoJavaScriptErrors();

    expect(EventDraft::query()->where('title', 'UAT Cheap Watches')->sole()->review_status)
        ->toBe(ReviewStatus::PendingReview);
});
