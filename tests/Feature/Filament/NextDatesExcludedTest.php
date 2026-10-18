<?php

use App\Enums\EventOrigin;
use App\Enums\RepeatPattern;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Category;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * 03-08 deferred item 2 (fixed in 03-13): the events edit form's "Next
 * dates" preview skips the series' excluded dates (EXDATE, D-19), like the
 * published series and the public site.
 */
it('leaves an excluded date out of the Next dates preview', function () {
    freezeLondon('2026-10-16 12:00');

    $admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($admin);

    $event = app(PublishEvent::class)(new PublishableEventData(
        title: 'Quiz Night',
        description: 'General knowledge quiz, teams of up to six.',
        categoryId: Category::factory()->create()->id,
        venueId: Venue::factory()->create()->id,
        startDate: '2026-10-23',
        startTime: '19:30:00',
        repeats: RepeatPattern::Weekly,
        organiserContact: 'organiser@example.test',
        excludedDates: ['2026-10-30'],
    ), $admin, EventOrigin::Manual);

    expect($event->excluded_dates)->toBe(['2026-10-30']);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->assertSee('Next dates')
        ->assertSeeInOrder(['Fri 23 Oct, 7:30pm', 'Fri 6 Nov, 7:30pm'])
        ->assertDontSee('Fri 30 Oct, 7:30pm');
});
