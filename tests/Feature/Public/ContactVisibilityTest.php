<?php

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Support\PublicUrl;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/*
 * D-03 / D-19 (T-02-11): an organiser's contact reaches residents only when
 * events.contact_public is true. The organiser name always shows. The
 * contact is still the "how to check this" detail for the admin.
 */
beforeEach(function () {
    freezeLondon('2026-10-16 12:00');

    actingAs(User::factory()->create(['email' => config('app.admin_email')]));

    $this->form = [
        'title' => 'Table Tennis Club Night',
        'description' => 'Drop-in session, bats provided.',
        'category_id' => Category::factory()->create()->id,
        'venue_id' => Venue::factory()->create(['name' => 'Connaught Hall'])->id,
        'start_date' => '2026-10-17',
        'start_time' => '19:00',
        'organiser_name' => 'Attleborough TT Club',
        'organiser_contact' => 'jo@club.example',
    ];
});

it('keeps a private contact off the event page and the calendar card', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->form, 'contact_public' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::sole();

    expect($event->contact_public)->toBeFalse()
        ->and($event->organiser_contact)->toBe('jo@club.example')
        ->and(EventDraft::sole()->contact_public)->toBeFalse();

    get(PublicUrl::eventPath($event))
        ->assertOk()
        ->assertSee('Attleborough TT Club')
        ->assertDontSee('jo@club.example')
        ->assertDontSee('Contact:');

    get('/')
        ->assertOk()
        ->assertSee('Table Tennis Club Night')
        ->assertSee('Attleborough TT Club')
        ->assertDontSee('jo@club.example');
});

it('shows the contact on both pages when the toggle is left on', function () {
    livewire(CreateEvent::class)
        ->fillForm($this->form)
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::sole();

    expect($event->contact_public)->toBeTrue();

    get(PublicUrl::eventPath($event))
        ->assertOk()
        ->assertSee('Attleborough TT Club')
        ->assertSee('jo@club.example');

    get('/')
        ->assertOk()
        ->assertSee('jo@club.example');
});

it('labels the website and contact visibility fields on the form', function () {
    livewire(CreateEvent::class)
        ->assertSee('Organiser website')
        ->assertSee('Show contact publicly')
        ->assertFormSet(['contact_public' => true]);
});

it('defaults contact_public to true for events that never set it', function () {
    $event = Event::factory()->create();

    expect($event->fresh()->contact_public)->toBeTrue();
});
