<?php

use App\Enums\EventOrigin;
use App\Enums\RepeatPattern;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use Filament\Forms\Components\Select;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * WR-06 (form): the edit page mirrors UpdatePublishedEvent's guard. A one-off
 * event cannot be made repeating and a series cannot be made one-off; the
 * helper text explains the workaround. The create page is unchanged.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);

    $this->publish = fn (array $overrides = []): Event => app(PublishEvent::class)(new PublishableEventData(...array_merge([
        'title' => 'Quiz Night',
        'description' => 'General knowledge quiz, teams of up to six.',
        'categoryId' => Category::factory()->create()->id,
        'venueId' => Venue::factory()->create()->id,
        'startDate' => '2026-10-23',
        'startTime' => '19:30:00',
        'organiserContact' => 'organiser@example.test',
    ], $overrides)), $this->admin, EventOrigin::Manual);
});

it('offers a one-off event only "Does not repeat" and explains how to make a repeating event', function () {
    $event = ($this->publish)();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormFieldExists('repeats', checkFieldUsing: fn (Select $field): bool => $field->getOptions() === [RepeatPattern::None->value => 'Does not repeat'])
        ->assertFormFieldDisabled('repeats')
        ->assertSee('hide it and create a new repeating event')
        ->assertSee('will stop working')
        ->assertSee('the new event gets its own web address');
});

it('does not offer "Does not repeat" for a series and explains how to end it', function () {
    $event = ($this->publish)(['repeats' => RepeatPattern::Weekly]);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormFieldExists('repeats', checkFieldUsing: fn (Select $field): bool => ! array_key_exists(RepeatPattern::None->value, $field->getOptions())
            && array_key_exists(RepeatPattern::Weekly->value, $field->getOptions()))
        ->assertSee('To stop it repeating, set a last date or hide it.');
});

it('still offers "Does not repeat" and weekly on the create page', function () {
    livewire(CreateEvent::class)
        ->fillForm(['start_date' => '2026-10-23'])
        ->assertFormFieldExists('repeats', checkFieldUsing: fn (Select $field): bool => array_key_exists(RepeatPattern::None->value, $field->getOptions())
            && array_key_exists(RepeatPattern::Weekly->value, $field->getOptions()))
        ->assertFormFieldEnabled('repeats')
        ->assertDontSee('create a new repeating event')
        ->assertDontSee('To stop it repeating');
});
