<?php

use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use Filament\Notifications\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * Tracer (REV-03): a submission draft → Edit → set the repeat rule and
 * confirm the suggested venue → Save & approve → the series is published
 * with its occurrences → the next pending draft's review page opens.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    actingAs(User::factory()->create(['email' => config('app.admin_email')]));

    $this->hall = Venue::factory()->create(['name' => 'Connaught Hall']);

    $this->draftA = EventDraft::factory()->submission()->create([
        'title' => 'Thursday Craft Club',
        'venue_id' => null,
        'venue_name' => 'Connaught Hall',
        'venue_address' => null,
        'venue_postcode' => null,
        'start_date' => '2026-10-22',
        'start_time' => '19:00:00',
        'end_time' => '21:00:00',
        'repeats_text' => 'Every Thursday for four weeks',
        'recurrence_rule' => null,
    ]);

    $this->draftB = EventDraft::factory()->create(['start_date' => '2026-10-25']);
});

it('pre-fills the suggested venue and shows the organiser repeats text', function () {
    livewire(EditEventDraft::class, ['record' => $this->draftA->id])
        ->assertOk()
        ->assertFormSet(['venue_id' => $this->hall->id])
        ->assertSee("Organiser says it repeats: 'Every Thursday for four weeks'");

    // The suggestion is only pre-selected: nothing is stored until a save.
    expect($this->draftA->refresh()->venue_id)->toBeNull();
});

it('sets the rule, confirms the venue, publishes the series and opens the next draft', function () {
    livewire(EditEventDraft::class, ['record' => $this->draftA->id])
        ->fillForm(['repeats' => 'weekly', 'repeat_ends' => 'count', 'repeat_count' => 4])
        ->call('saveAndApprove')
        ->assertHasNoFormErrors()
        ->assertNotified('Published')
        ->assertRedirect(EventDraftResource::getUrl('review', ['record' => $this->draftB]));

    $draft = $this->draftA->refresh();
    $event = Event::sole();

    expect($draft->review_status)->toBe(ReviewStatus::Approved)
        ->and($draft->venue_id)->toBe($this->hall->id)
        ->and($draft->recurrence_rule)->toBe('FREQ=WEEKLY;BYDAY=TH;COUNT=4')
        ->and($draft->event_id)->toBe($event->id)
        ->and($event->venue_id)->toBe($this->hall->id)
        ->and($event->recurrence_rule)->toBe('FREQ=WEEKLY;BYDAY=TH;COUNT=4')
        ->and(EventOccurrence::query()->where('event_id', $event->id)->count())->toBe(4);
});

it('saves and publishes nothing when a blocker remains, keeping the typed values in the form', function () {
    livewire(EditEventDraft::class, ['record' => $this->draftA->id])
        ->fillForm(['title' => 'Craft Club (edited)'])
        ->call('saveAndApprove')
        ->assertHasNoFormErrors()
        ->assertNotified(
            Notification::make()
                ->warning()
                ->title("Can't publish yet")
                ->body("The organiser says it repeats. Set a repeat rule, or tick 'No repeat rule needed'."),
        )
        ->assertNoRedirect()
        ->assertFormSet(['title' => 'Craft Club (edited)', 'venue_id' => $this->hall->id]);

    $draft = $this->draftA->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->title)->toBe('Thursday Craft Club')
        ->and($draft->venue_id)->toBeNull()
        ->and(Event::count())->toBe(0);
});

it('lands on the queue with a notice when the approved draft was the last one', function () {
    $this->draftB->delete();

    livewire(EditEventDraft::class, ['record' => $this->draftA->id])
        ->fillForm(['repeats' => 'weekly', 'repeat_ends' => 'count', 'repeat_count' => 4])
        ->call('saveAndApprove')
        ->assertNotified("You've reached the end of the queue")
        ->assertRedirect(EventDraftResource::getUrl('index'));

    expect($this->draftA->refresh()->review_status)->toBe(ReviewStatus::Approved);
});
