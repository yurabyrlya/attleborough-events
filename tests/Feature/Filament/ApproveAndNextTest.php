<?php

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Review\DraftPublishability;
use Filament\Notifications\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * REV-10 "Approve & next" (D-09, D-10): every decision on the review page
 * opens the next pending draft in queue order; Skip and Previous walk the
 * queue; a keyboard A on a blocked draft explains why and approves nothing.
 * The keys themselves are proven in tests/Browser/ApproveAndNextKeyboardTest.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    actingAs(User::factory()->create(['email' => config('app.admin_email')]));

    $this->draftA = EventDraft::factory()->create(['title' => 'Draft A', 'start_date' => '2026-10-22']);
    $this->draftB = EventDraft::factory()->create(['title' => 'Draft B', 'start_date' => '2026-10-23']);
    $this->draftC = EventDraft::factory()->create(['title' => 'Draft C', 'start_date' => '2026-10-24']);

    $this->page = fn (EventDraft $draft) => livewire(ReviewEventDraft::class, ['record' => $draft->id]);
    $this->reviewUrl = fn (EventDraft $draft): string => EventDraftResource::getUrl('review', ['record' => $draft]);
});

it('approves and opens the next pending draft', function () {
    ($this->page)($this->draftA)
        ->assertActionEnabled('approve')
        ->callAction('approve')
        ->assertNotified('Published')
        ->assertRedirect(($this->reviewUrl)($this->draftB));

    expect($this->draftA->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and(Event::count())->toBe(1);
});

it('skips to the next pending draft without changing the current one', function () {
    ($this->page)($this->draftA)
        ->callAction('skip')
        ->assertRedirect(($this->reviewUrl)($this->draftB));

    expect($this->draftA->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
});

it('goes back to the previous pending draft and hides Previous on the first one', function () {
    ($this->page)($this->draftB)
        ->assertActionVisible('previous')
        ->callAction('previous')
        ->assertRedirect(($this->reviewUrl)($this->draftA));

    ($this->page)($this->draftA)->assertActionHidden('previous');
});

it('rejects and opens the next pending draft', function () {
    ($this->page)($this->draftA)
        ->callAction('reject', data: [])
        ->assertNotified('Draft rejected')
        ->assertRedirect(($this->reviewUrl)($this->draftB));

    expect($this->draftA->refresh()->review_status)->toBe(ReviewStatus::Rejected);
});

it('opens the queue with a notice after approving the last pending draft', function () {
    $this->draftA->delete();
    $this->draftB->delete();

    ($this->page)($this->draftC)
        ->callAction('approve')
        ->assertNotified(EditEventDraft::END_OF_QUEUE)
        ->assertRedirect(EventDraftResource::getUrl('index'));

    expect($this->draftC->refresh()->review_status)->toBe(ReviewStatus::Approved);
});

it('opens the queue with a notice when skipping the last pending draft', function () {
    ($this->page)($this->draftC)
        ->callAction('skip')
        ->assertNotified(EditEventDraft::END_OF_QUEUE)
        ->assertRedirect(EventDraftResource::getUrl('index'));
});

it('warns and approves nothing when A is pressed on a draft that cannot be published', function () {
    $blocked = EventDraft::factory()->freeTextVenue()->create(['start_date' => '2026-10-21']);
    $blockers = app(DraftPublishability::class)->blockers($blocked);

    expect($blockers)->toContain('Pick or create a venue.');

    ($this->page)($blocked)
        ->call('approveFromKeyboard')
        ->assertNotified(Notification::make()->warning()->title("Can't approve yet")->body(implode(' ', $blockers)))
        ->assertNoRedirect();

    expect($blocked->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
});

it('opens the approve confirmation when A is pressed on a publishable draft', function () {
    ($this->page)($this->draftA)
        ->call('approveFromKeyboard')
        ->assertActionMounted('approve')
        ->assertMountedActionModalSee('Publish this event?');

    expect($this->draftA->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('lists every shortcut in the help dialog', function () {
    ($this->page)($this->draftA)
        ->mountAction('help')
        ->assertMountedActionModalSee([
            '<kbd>A</kbd> Approve &amp; next',
            '<kbd>E</kbd> Edit',
            '<kbd>R</kbd> Reject',
            '<kbd>S</kbd> or <kbd>J</kbd> Skip',
            '<kbd>K</kbd> Previous',
            '<kbd>?</kbd> Show this help',
            'Shortcuts are off while you type in a field or a dialog is open.',
        ], escape: false);
});

it('shows the shortcuts toggle and the key on every action button', function () {
    ($this->page)($this->draftB)
        ->assertSee('Keyboard shortcuts')
        ->assertSee('woa-shortcuts')
        ->assertSee(['Approve &amp; next (A)', 'Edit (E)', 'Reject (R)', 'Skip (S)', 'Previous (K)'], escape: false)
        ->assertSeeHtml('aria-keyshortcuts="A"')
        ->assertSeeHtml('aria-keyshortcuts="E"')
        ->assertSeeHtml('aria-keyshortcuts="R"')
        ->assertSeeHtml('aria-keyshortcuts="S J"')
        ->assertSeeHtml('aria-keyshortcuts="K"');
});

it('links Edit to the edit page', function () {
    ($this->page)($this->draftA)
        ->assertActionHasUrl('edit', EventDraftResource::getUrl('edit', ['record' => $this->draftA]));
});

it('offers no decision actions on an approved draft', function () {
    ($this->page)($this->draftA)->callAction('approve');

    ($this->page)($this->draftA->refresh())
        ->assertActionHidden('approve')
        ->assertActionHidden('edit')
        ->assertActionHidden('reject')
        ->assertActionHidden('skip')
        ->call('approveFromKeyboard')
        ->assertNoRedirect();

    expect(Event::count())->toBe(1);
});
