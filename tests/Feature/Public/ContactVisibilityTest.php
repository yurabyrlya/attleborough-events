<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Data\PublishableEventData;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Support\PublicUrl;
use Carbon\CarbonImmutable;

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

it('carries a draft\'s website and private contact to the approved event', function () {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Manual,
        title: 'Quiz Night',
        description: 'Teams of up to six.',
        preferredCategoryId: $this->form['category_id'],
        venueId: $this->form['venue_id'],
        startDate: '2026-10-23',
        startTime: '19:30:00',
        organiserName: 'Attleborough Lions',
        organiserContact: 'lions@club.example',
        organiserWebsite: 'https://club.example/events',
        contactPublic: false,
        consentedAt: CarbonImmutable::now(),
    ));

    $publishable = PublishableEventData::fromDraft($draft);

    expect($publishable->organiserWebsite)->toBe('https://club.example/events')
        ->and($publishable->contactPublic)->toBeFalse();

    $event = app(ApproveDraft::class)($draft, User::query()->sole());

    expect($event->fresh()->contact_public)->toBeFalse()
        ->and($event->fresh()->organiser_website)->toBe('https://club.example/events');

    get(PublicUrl::eventPath($event))
        ->assertOk()
        ->assertDontSee('lions@club.example')
        ->assertSee('Website:');
});

it('publishes a draft whose only "how to check this" detail is a website', function () {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Manual,
        title: 'Craft Fair',
        description: 'Local makers.',
        preferredCategoryId: $this->form['category_id'],
        venueId: $this->form['venue_id'],
        startDate: '2026-10-24',
        startTime: '10:00:00',
        organiserWebsite: 'https://crafts.example',
        consentedAt: CarbonImmutable::now(),
    ));

    $event = app(ApproveDraft::class)($draft, User::query()->sole());

    expect($event->fresh()->organiser_website)->toBe('https://crafts.example');
});

it('links the organiser website by host with nofollow ugc noopener', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->form, 'organiser_website' => 'https://www.club.example/events'])
        ->call('create')
        ->assertHasNoFormErrors();

    get(PublicUrl::eventPath(Event::sole()))
        ->assertOk()
        ->assertSee(
            'Website: <a href="https://www.club.example/events" rel="nofollow ugc noopener">club.example</a>',
            escape: false,
        );
});

it('never renders a non-http website as a link', function () {
    // The form's url() rule refuses this; the read side must still not link it.
    $event = app(PublishEvent::class)(
        new PublishableEventData(
            title: 'Quiz Night',
            description: 'Teams of up to six.',
            categoryId: $this->form['category_id'],
            venueId: $this->form['venue_id'],
            startDate: '2026-10-23',
            startTime: '19:30',
            organiserWebsite: 'javascript:alert(1)',
        ),
        User::query()->sole(),
        EventOrigin::Manual,
    );

    get(PublicUrl::eventPath($event))
        ->assertOk()
        ->assertSee('Quiz Night')
        ->assertDontSee('javascript:alert(1)', escape: false)
        ->assertDontSee('Website:')
        ->assertDontSee('ugc');
});

it('accepts a website alone as the "how to check this" detail on the form', function () {
    livewire(CreateEvent::class)
        ->fillForm([
            ...$this->form,
            'organiser_contact' => null,
            'source_url' => null,
            'organiser_website' => 'https://club.example',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::sole()->organiser_website)->toBe('https://club.example');
});

it('asks for a source URL, contact or website when none is given', function () {
    livewire(CreateEvent::class)
        ->fillForm([...$this->form, 'organiser_contact' => null, 'source_url' => null, 'organiser_website' => null])
        ->call('create')
        ->assertHasFormErrors(['source_url'])
        ->assertSee('Add a source URL, an organiser contact or a website.');

    expect(Event::count())->toBe(0);
});
