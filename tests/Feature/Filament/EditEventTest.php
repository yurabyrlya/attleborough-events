<?php

use App\Enums\EventOrigin;
use App\Enums\RepeatEnds;
use App\Enums\RepeatPattern;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'Europe/London'));
    $this->admin = User::factory()->create(['email' => 'admin@example.test']);
    actingAs($this->admin);

    $this->publish = function (array $overrides = []): Event {
        return app(PublishEvent::class)(new PublishableEventData(...array_merge([
            'title' => 'Last Friday Ceilidh',
            'description' => 'Callers, a band and a bar.',
            'categoryId' => Category::factory()->create()->id,
            'venueId' => Venue::factory()->create(['name' => 'Town Hall'])->id,
            'startDate' => '2026-10-30',
            'startTime' => '19:30:00',
            'organiserContact' => 'ceilidh@example.test',
            'repeats' => RepeatPattern::MonthlyLast,
            'repeatEnds' => RepeatEnds::OnDate,
            'repeatUntil' => '2027-03-31',
        ], $overrides)), $this->admin, EventOrigin::Manual);
    };
});

it('opens the edit form hydrated with the Repeats and Ends fields from the stored rule', function () {
    $event = ($this->publish)();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormSet([
            'title' => 'Last Friday Ceilidh',
            'start_date' => '2026-10-30',
            'repeats' => 'monthly_last',
            'repeat_ends' => 'until',
            'repeat_until' => '2027-03-31',
        ]);
});

it('saves an edit through the form and shows the change on the public list', function () {
    $event = ($this->publish)();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['start_time' => '20:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->recurrence_rule)->toBe('FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270331T225959Z');

    get('/')->assertOk()->assertSee('Fri 30 Oct, 8pm')->assertDontSee('Fri 30 Oct, 7:30pm');
});

it('has no delete action on the edit page', function () {
    $event = ($this->publish)();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertActionDoesNotExist('delete');
});

it('keeps the public order by id when an edit makes two occurrences share a start time', function () {
    $first = ($this->publish)(['title' => 'Alpha Talk', 'startDate' => '2026-10-24', 'startTime' => '11:00:00', 'repeats' => RepeatPattern::None, 'repeatEnds' => RepeatEnds::Never, 'repeatUntil' => null]);
    $second = ($this->publish)(['title' => 'Beta Talk', 'startDate' => '2026-10-24', 'startTime' => '10:00:00', 'repeats' => RepeatPattern::None, 'repeatEnds' => RepeatEnds::Never, 'repeatUntil' => null]);

    livewire(EditEvent::class, ['record' => $second->getRouteKey()])
        ->fillForm(['start_time' => '11:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($first->id)->toBeLessThan($second->id);
    get('/')->assertOk()->assertSeeInOrder(['Alpha Talk', 'Beta Talk']);
});

it('lists the series occurrences sorted by start, shown in London time', function () {
    $event = ($this->publish)(['repeatEnds' => RepeatEnds::Never, 'repeatUntil' => null]);
    $occurrences = $event->occurrences()->orderBy('starts_at')->get();

    livewire(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertCanSeeTableRecords($occurrences, inOrder: true)
        ->assertSee('Fri 30 Oct 2026, 19:30')
        ->assertSee('Fri 27 Nov 2026, 19:30');
});

it('offers no create, edit or delete action on the occurrences table', function () {
    $event = ($this->publish)();
    $occurrence = $event->occurrences()->orderBy('starts_at')->firstOrFail();

    livewire(OccurrencesRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertActionDoesNotExist('create')
        ->assertActionDoesNotExist(TestAction::make('edit')->table($occurrence))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($occurrence));
});

it('shows a Repeats column and an edit action in the events table', function () {
    $event = ($this->publish)();

    livewire(ListEvents::class)
        ->assertSee('Monthly on the last Friday')
        ->assertActionExists(TestAction::make('edit')->table($event));
});
