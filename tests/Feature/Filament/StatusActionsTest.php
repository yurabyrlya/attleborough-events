<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Filament\Resources\Events\Actions\StatusActions;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\ChangeOccurrenceStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * Filament status actions: visibility follows canTransitionTo(), every action
 * asks for confirmation, and each one delegates to a domain action.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    $this->publish = fn (array $overrides = []): Event => app(PublishEvent::class)(new PublishableEventData(...array_merge([
        'title' => 'Harvest Supper',
        'description' => 'Soup, bread and a raffle.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-24',
        'startTime' => '18:00:00',
    ], $overrides)), $this->admin, EventOrigin::Manual);

    $this->event = ($this->publish)();

    // Four Fridays: fits on one table page so order assertions see every row.
    $this->series = ($this->publish)([
        'title' => 'Repair Café',
        'startDate' => '2026-10-23',
        'startTime' => '10:00:00',
        'repeats' => RepeatPattern::Weekly,
        'repeatEnds' => RepeatEnds::AfterCount,
        'repeatCount' => 4,
    ]);

    $this->rows = fn () => EventOccurrence::query()->where('event_id', $this->series->id)->orderBy('starts_at')->get();

    $this->setEvent = fn (EventStatus $to): Event => app(ChangeEventStatus::class)($this->event, $to, $this->admin);

    $this->editPage = fn () => livewire(EditEvent::class, ['record' => $this->event->getRouteKey()]);

    $this->occurrencesTable = fn () => livewire(OccurrencesRelationManager::class, ['ownerRecord' => $this->series, 'pageClass' => EditEvent::class]);
});

it('offers Hide and Mark as cancelled for a published event', function () {
    ($this->editPage)()
        ->assertActionVisible('hide')
        ->assertActionVisible('cancel')
        ->assertActionHidden('unhide')
        ->assertActionHidden('reinstate');
});

it('offers only Show on calendar for a hidden event', function () {
    ($this->setEvent)(EventStatus::Hidden);

    ($this->editPage)()
        ->assertActionVisible('unhide')
        ->assertActionHidden('hide')
        ->assertActionHidden('cancel')
        ->assertActionHidden('reinstate');
});

it('offers only Reinstate for a cancelled event', function () {
    ($this->setEvent)(EventStatus::Cancelled);

    ($this->editPage)()
        ->assertActionVisible('reinstate')
        ->assertActionHidden('hide')
        ->assertActionHidden('cancel')
        ->assertActionHidden('unhide');
});

it('hides then shows the event from its edit page', function () {
    $page = ($this->editPage)();

    $page->callAction('hide')->assertNotified('Event hidden');
    expect($this->event->fresh()->status)->toBe(EventStatus::Hidden);

    $page->assertActionVisible('unhide')->callAction('unhide')->assertNotified('Event shown on the calendar');
    expect($this->event->fresh()->status)->toBe(EventStatus::Published);
});

it('cancels then reinstates the event from its edit page', function () {
    $page = ($this->editPage)();

    $page->callAction('cancel')->assertNotified('Event marked as cancelled');
    expect($this->event->fresh()->status)->toBe(EventStatus::Cancelled);

    $page->assertActionVisible('reinstate')->callAction('reinstate')->assertNotified('Event reinstated');
    expect($this->event->fresh()->status)->toBe(EventStatus::Published);
});

it('requires confirmation for every event and occurrence status action', function () {
    $eventActions = collect(StatusActions::forEvent());
    $occurrenceActions = collect(StatusActions::forOccurrence());

    expect($eventActions->map->getName()->all())->toBe(['hide', 'unhide', 'cancel', 'reinstate'])
        ->and($occurrenceActions->map->getName()->all())->toBe(['cancel', 'hide', 'restore'])
        ->and($eventActions->merge($occurrenceActions)->every->isConfirmationRequired())->toBeTrue();
});

it('changes nothing when a hidden action is called', function () {
    // A crafted Livewire call: Filament refuses to run an action whose visible() is false.
    ($this->editPage)()
        ->assertActionExists('reinstate')
        ->assertActionHidden('reinstate')
        ->call('mountAction', 'reinstate')
        ->call('callMountedAction');

    expect($this->event->fresh()->status)->toBe(EventStatus::Published)
        ->and($this->event->fresh()->status_changed_at)->toBeNull();
});

it('offers Cancel and Hide on a scheduled row and only Restore on cancelled or hidden rows', function () {
    [$scheduled, $cancelled, $hidden] = ($this->rows)()->take(3)->all();
    app(ChangeOccurrenceStatus::class)($cancelled, OccurrenceStatus::Cancelled, $this->admin);
    app(ChangeOccurrenceStatus::class)($hidden, OccurrenceStatus::Hidden, $this->admin);

    ($this->occurrencesTable)()
        ->assertActionExists(TestAction::make('hide')->table($scheduled))
        ->assertActionExists(TestAction::make('restore')->table($scheduled))
        ->assertActionVisible(TestAction::make('cancel')->table($scheduled))
        ->assertActionVisible(TestAction::make('hide')->table($scheduled))
        ->assertActionHidden(TestAction::make('restore')->table($scheduled))
        ->assertActionVisible(TestAction::make('restore')->table($cancelled))
        ->assertActionHidden(TestAction::make('cancel')->table($cancelled))
        ->assertActionHidden(TestAction::make('hide')->table($cancelled))
        ->assertActionVisible(TestAction::make('restore')->table($hidden))
        ->assertActionHidden(TestAction::make('cancel')->table($hidden))
        ->assertActionHidden(TestAction::make('hide')->table($hidden));
});

it('restores a hidden row to scheduled from the Occurrences table', function () {
    $row = ($this->rows)()->first();
    app(ChangeOccurrenceStatus::class)($row, OccurrenceStatus::Hidden, $this->admin);

    ($this->occurrencesTable)()
        ->assertActionExists(TestAction::make('restore')->table($row))
        ->callAction(TestAction::make('restore')->table($row))
        ->assertNotified('Date restored');

    expect($row->fresh()->status)->toBe(OccurrenceStatus::Scheduled);
});

it('keeps the Occurrences table in starts_at ascending order after an action', function () {
    $rows = ($this->rows)();

    ($this->occurrencesTable)()
        ->assertActionExists(TestAction::make('hide')->table($rows[2]))
        ->callAction(TestAction::make('hide')->table($rows[2]))
        ->assertNotified('Date hidden')
        ->assertCanSeeTableRecords($rows, inOrder: true);

    expect($rows[2]->fresh()->status)->toBe(OccurrenceStatus::Hidden);
});

it('filters the Occurrences table by status', function () {
    $rows = ($this->rows)();
    app(ChangeOccurrenceStatus::class)($rows[1], OccurrenceStatus::Cancelled, $this->admin);

    ($this->occurrencesTable)()
        ->filterTable('status', OccurrenceStatus::Cancelled->value)
        ->assertCanSeeTableRecords([$rows[1]])
        ->assertCanNotSeeTableRecords([$rows[0], $rows[2], $rows[3]]);
});
