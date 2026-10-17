<?php

use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * The edit-and-approve page (REV-03): venues (D-05), repeats (D-06, D-10),
 * partial saves, unrepresentable rules (Pitfall 11) and stale drafts.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);
});

function createVenueAction(): TestAction
{
    return TestAction::make('createOption')->schemaComponent('venue_id', 'form');
}

it('opens Create new venue pre-filled from the typed venue and creates it only on submit', function () {
    $draft = EventDraft::factory()->submission()->freeTextVenue()->create();
    $venuesBefore = Venue::count();

    $page = livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertFormSet(['venue_id' => null])
        ->assertActionHasLabel(createVenueAction(), 'Create new venue')
        ->mountAction(createVenueAction())
        ->assertActionDataSet([
            'name' => 'The Old Barn',
            'address' => '1 Church Street',
            'postcode' => 'NR17 2AA',
            'town' => 'Attleborough',
        ]);

    expect(Venue::count())->toBe($venuesBefore);

    $page->callMountedAction()->assertHasNoFormErrors();

    expect(Venue::count())->toBe($venuesBefore + 1);

    $venue = Venue::query()->where('name', 'The Old Barn')->sole();
    $page->assertFormSet(['venue_id' => $venue->id]);

    // Selected in the form only: the draft gets it when the admin saves.
    expect($draft->refresh()->venue_id)->toBeNull();

    $page->call('save')->assertHasNoFormErrors();

    expect($draft->refresh()->venue_id)->toBe($venue->id);
});

it('shows the organiser typed venue next to the picker', function () {
    $draft = EventDraft::factory()->submission()->freeTextVenue()->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertSee('Organiser typed venue')
        ->assertSee('The Old Barn, 1 Church Street, NR17 2AA');
});

it('publishes a one-off when No repeat rule needed is ticked', function () {
    $draft = EventDraft::factory()->submission()->withRepeatsText('Maybe again at Christmas')->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertFormFieldVisible('repeats_acknowledged')
        ->fillForm(['repeats_acknowledged' => true])
        ->call('saveAndApprove')
        ->assertHasNoFormErrors()
        ->assertNotified('Published');

    $draft->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::Approved)
        ->and($draft->repeats_acknowledged)->toBeTrue()
        ->and($draft->recurrence_rule)->toBeNull()
        ->and(Event::sole()->recurrence_rule)->toBeNull();
});

it('hides No repeat rule needed when the organiser gave no repeats text', function () {
    $draft = EventDraft::factory()->submission()->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertFormFieldHidden('repeats_acknowledged')
        ->assertDontSee('Organiser says it repeats');
});

it('keeps the draft pending on Save and lifts the score when an end time is added', function () {
    $draft = EventDraft::factory()->submission()->create(['end_time' => null]);

    livewire(EditEventDraft::class, ['record' => $draft->id])->call('save')->assertHasNoFormErrors();
    $before = $draft->refresh()->quality_score;

    expect($draft->quality_reasons)->toContain('No end time');

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['end_time' => '22:00'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Draft saved')
        ->assertNoRedirect();

    $draft->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->end_time)->toBe('22:00:00')
        ->and($draft->quality_score)->toBeGreaterThan($before)
        ->and($draft->quality_reasons)->not->toContain('No end time')
        ->and(Event::count())->toBe(0);
});

it('stores Chosen by admin when the admin changes the category, and keeps the organiser pick otherwise', function () {
    $draft = EventDraft::factory()->submission()->create();
    $other = Category::factory()->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['title' => 'Renamed only'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh()->category_reason)->toBe('Chosen by organiser');

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['category_id' => $other->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $draft->refresh();

    expect($draft->category_id)->toBe($other->id)
        ->and($draft->category_reason)->toBe('Chosen by admin')
        ->and($draft->category_flagged)->toBeFalse();

    // An admin's pick stays the admin's on later saves.
    livewire(EditEventDraft::class, ['record' => $draft->id])->call('save')->assertHasNoFormErrors();

    expect($draft->refresh()->category_reason)->toBe('Chosen by admin');
});

it('saves a draft without a venue and leaves it blocked from approval', function () {
    $draft = EventDraft::factory()->submission()->freeTextVenue()->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['title' => 'Barn Dance'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Draft saved');

    $draft->refresh();

    expect($draft->title)->toBe('Barn Dance')
        ->and($draft->venue_id)->toBeNull()
        ->and($draft->review_status)->toBe(ReviewStatus::PendingReview);

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->call('saveAndApprove')
        ->assertNotified(Notification::make()->warning()->title("Can't publish yet")->body('Pick or create a venue.'));

    expect(Event::count())->toBe(0);
});

it('shows a rule the form cannot represent read-only, keeps it on Save and refuses Save & approve', function () {
    $draft = EventDraft::factory()->submission()->recurring('FREQ=DAILY', '2026-10-22', '19:00:00')->create();

    $page = livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertSee("This repeat rule can't be edited here")
        ->assertSee('It came from the organiser or a source and needs a different rule type.')
        ->assertFormSet(['repeats' => 'custom'])
        ->assertFormFieldDisabled('repeats');

    // A forged Repeats value is refused and cannot drop the stored rule.
    $page->set('data.repeats', 'none')
        ->call('save')
        ->assertHasFormErrors(['repeats']);

    expect($draft->refresh()->recurrence_rule)->toBe('FREQ=DAILY');

    $page->set('data.repeats', 'custom')
        ->fillForm(['title' => 'Daily Walk'])
        ->call('save')
        ->assertHasNoFormErrors();

    $draft->refresh();

    expect($draft->title)->toBe('Daily Walk')
        ->and($draft->recurrence_rule)->toBe('FREQ=DAILY');

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->call('saveAndApprove')
        ->assertNotified(Notification::make()->warning()->title("Can't publish yet")->body("This repeat rule can't be published from the form."));

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->recurrence_rule)->toBe('FREQ=DAILY')
        ->and(Event::count())->toBe(0);
});

it('redirects the edit page of a decided draft to its review page', function (string $status) {
    $draft = $status === 'approved'
        ? EventDraft::factory()->approvedFor(Event::factory()->create())->create()
        : EventDraft::factory()->rejected(RejectReason::Spam, CarbonImmutable::now())->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertNotified(ReviewActions::ALREADY_REVIEWED)
        ->assertRedirect(EventDraftResource::getUrl('review', ['record' => $draft]));
})->with(['approved', 'rejected']);

it('changes nothing when Save hits a draft approved in another tab', function () {
    $draft = EventDraft::factory()->submission()->create(['title' => 'Original']);

    $page = livewire(EditEventDraft::class, ['record' => $draft->id]);

    $event = Event::factory()->create();
    EventDraft::query()->whereKey($draft->id)->update([
        'review_status' => ReviewStatus::Approved->value,
        'event_id' => $event->id,
    ]);

    $page->fillForm(['title' => 'Edited in a stale tab'])
        ->call('save')
        ->assertNotified(ReviewActions::ALREADY_REVIEWED);

    expect($draft->refresh()->title)->toBe('Original');

    $page->call('saveAndApprove')->assertNotified(ReviewActions::ALREADY_REVIEWED);

    expect($draft->refresh()->title)->toBe('Original')
        ->and(Event::count())->toBe(1);
});

it('shows the organiser repeats text as text, never as markup', function () {
    $draft = EventDraft::factory()->submission()->withRepeatsText('<b>weekly</b>')->create();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertSee("Organiser says it repeats: '<b>weekly</b>'")
        ->assertDontSee('<b>weekly</b>', false);
});

it('refuses a non-admin on the edit page', function () {
    $draft = EventDraft::factory()->create();
    actingAs(User::factory()->create(['email' => 'someone@example.test']));

    get(EventDraftResource::getUrl('edit', ['record' => $draft]))->assertForbidden();
});
