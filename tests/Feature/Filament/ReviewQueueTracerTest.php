<?php

use App\Enums\QualityBand;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * Tracer: Review queue → review page → Approve and publish → public calendar.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
});

it('lists a pending draft and badges the navigation with the pending count', function () {
    actingAs($this->admin);
    $draft = EventDraft::factory()->create(['title' => 'Harvest Supper']);
    EventDraft::factory()->create();

    livewire(ListEventDrafts::class)
        ->assertOk()
        ->assertSee('Harvest Supper')
        ->assertCanSeeTableRecords([$draft]);

    expect(EventDraftResource::getNavigationBadge())->toBe('2')
        ->and(EventDraftResource::getNavigationGroup())->toBe('Review')
        ->and(EventDraftResource::getNavigationLabel())->toBe('Review queue');
});

it('shows no badge when nothing is pending', function () {
    actingAs($this->admin);

    expect(EventDraftResource::getNavigationBadge())->toBeNull();
});

it('opens the review page, approves the draft and shows it on the public calendar', function () {
    actingAs($this->admin);
    $draft = EventDraft::factory()->scored(QualityBand::Medium, 70, ['No end time'])->create(['title' => 'Harvest Supper']);

    $this->get(EventDraftResource::getUrl('review', ['record' => $draft]))->assertOk()->assertSee('Harvest Supper');

    livewire(ReviewEventDraft::class, ['record' => $draft->id])
        ->assertOk()
        ->assertSee('Harvest Supper')
        ->assertSee('Medium')
        ->assertSee('70/100')
        ->assertSee('No end time')
        ->assertActionVisible('approve')
        ->assertActionEnabled('approve')
        ->callAction('approve')
        ->assertNotified('Published')
        ->assertRedirect(EventDraftResource::getUrl('index'));

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and(Event::count())->toBe(1);

    get('/')->assertOk()->assertSee('Harvest Supper');
});

it('shows Nothing to fix for a draft with no reasons', function () {
    actingAs($this->admin);
    $draft = EventDraft::factory()->create();

    livewire(ReviewEventDraft::class, ['record' => $draft->id])
        ->assertSee('100/100')
        ->assertSee('Nothing to fix');
});

it('lists the blockers and disables approve while the draft has no venue', function () {
    actingAs($this->admin);
    $draft = EventDraft::factory()->freeTextVenue()->create();

    livewire(ReviewEventDraft::class, ['record' => $draft->id])
        ->assertSee('Before you can publish')
        ->assertSee('Pick or create a venue.')
        ->assertActionDisabled('approve');

    expect(Event::count())->toBe(0);
});

it('hides the blockers section when the draft can be published', function () {
    actingAs($this->admin);
    $draft = EventDraft::factory()->create();

    livewire(ReviewEventDraft::class, ['record' => $draft->id])
        ->assertDontSee('Before you can publish');
});

it('refuses a non-admin on the list and the review page', function () {
    $draft = EventDraft::factory()->create();
    actingAs(User::factory()->create(['email' => 'someone@example.test']));

    get(EventDraftResource::getUrl('index'))->assertForbidden();
    get(EventDraftResource::getUrl('review', ['record' => $draft]))->assertForbidden();
});
