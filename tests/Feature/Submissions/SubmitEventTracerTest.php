<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * SUB-01 / SUB-02 tracer: an organiser fills /submit, the request is
 * validated, DraftWriter creates ONE pending submission draft, and
 * /submit/thanks shows the reference. Nothing reaches the calendar.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');
    $this->seed(CategorySeeder::class);
    $this->community = Category::where('slug', 'community')->firstOrFail();
    $this->venue = Venue::factory()->create(['name' => 'Connaught Hall']);
});

it('shows the form with every field, the venue choice, Not sure and the consent link', function () {
    $response = get('/submit')->assertOk();

    $response->assertSee('Add your event')
        ->assertSee('Send for review')
        ->assertSee('Somewhere else')
        ->assertSee('Not sure')
        ->assertSee('Connaught Hall')
        ->assertSee('Does it repeat?')
        ->assertSee('Show my contact publicly')
        ->assertSee('data-venue-picker', false)
        ->assertSee('data-venue-other', false)
        ->assertSee('href="/privacy"', false);

    foreach ([
        'title', 'description', 'category_choice', 'start_date', 'all_day', 'start_time', 'end_date',
        'end_time', 'repeats_text', 'venue_choice', 'venue_name', 'venue_address', 'venue_postcode',
        'price_type_free', 'price_text', 'organiser_name', 'organiser_email', 'organiser_website',
        'contact_public', 'source_url', 'consent',
    ] as $id) {
        $response->assertSee('for="'.$id.'"', false);
        $response->assertSee('id="'.$id.'"', false);
    }
});

it('turns a valid submission into one pending draft and keeps it off the calendar', function () {
    $response = post('/submit', validSubmission([
        'title' => 'Spring Craft Fair',
        'venue_choice' => (string) $this->venue->id,
        'category_choice' => (string) $this->community->id,
    ]));

    $response->assertRedirect(route('submit.thanks'));

    $draft = EventDraft::sole();

    expect($draft->origin)->toBe(EventOrigin::Submission)
        ->and($draft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->extraction_method)->toBe(ExtractionMethod::Submission)
        ->and($draft->venue_id)->toBe($this->venue->id)
        ->and($draft->submitted_category_id)->toBe($this->community->id)
        ->and($draft->category_id)->toBe($this->community->id)
        ->and($draft->category_reason)->toBe('Chosen by organiser')
        ->and($draft->contact_public)->toBeFalse()
        ->and($draft->consented_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and($draft->raw_payload['title'])->toBe('Spring Craft Fair')
        ->and($draft->raw_payload)->not->toHaveKey('consent')
        ->and($draft->recurrence_rule)->toBeNull()
        ->and(Event::count())->toBe(0)
        ->and(EventOccurrence::count())->toBe(0);

    get('/')->assertOk()->assertDontSee('Spring Craft Fair');
});

it('shows the will-be-reviewed message and the reference after the redirect', function () {
    $response = $this->followingRedirects()->post('/submit', validSubmission([
        'venue_choice' => (string) $this->venue->id,
    ]));

    $reference = EventDraft::sole()->reference;

    $response->assertOk()
        ->assertSee("Thanks! We'll check the details and add it to the calendar, usually within a few days.", false)
        ->assertSee('Your reference: '.$reference)
        ->assertSee("Back to what's on", false);

    expect($reference)->toStartWith('WOA-');
});

it('stores a typed venue when the organiser picks Somewhere else', function () {
    post('/submit', validSubmission([
        'venue_choice' => 'other',
        'venue_name' => 'The Old Barn',
        'venue_address' => 'Church Street, Attleborough',
        'venue_postcode' => 'NR17 2AH',
    ]))->assertRedirect(route('submit.thanks'));

    $draft = EventDraft::sole();

    expect($draft->venue_id)->toBeNull()
        ->and($draft->venue_name)->toBe('The Old Barn')
        ->and($draft->venue_address)->toBe('Church Street, Attleborough')
        ->and($draft->venue_postcode)->toBe('NR17 2AH');
});

it('loads the venue reveal script from /submit only', function () {
    $form = (string) file_get_contents(resource_path('views/submit/create.blade.php'));
    $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
    $script = (string) file_get_contents(resource_path('js/submit.js'));

    expect($form)->toContain("@vite('resources/js/submit.js')")
        ->and($layout)->not->toContain('submit.js')
        ->and((string) file_get_contents(base_path('vite.config.js')))->toContain("'resources/js/submit.js'")
        ->and($script)->toContain('[data-venue-picker]')
        ->and($script)->toContain('[data-venue-other]')
        ->and($script)->not->toContain('innerHTML');
});

dataset('public pages', [
    'calendar' => ['/'],
    'submit form' => ['/submit'],
    'privacy notice' => ['/privacy'],
]);

it('links every public page footer to the form and the privacy notice', function (string $path) {
    $response = get($path)->assertOk();

    assertLink($response, '/submit', 'Add your event');
    assertLink($response, '/privacy', 'Privacy');

    expect((string) $response->getContent())
        ->toMatch('#<footer\b.*<a href="/submit" class="[^"]*tap-target[^"]*">Add your event</a>.*</footer>#s')
        ->toMatch('#<footer\b.*<a href="/privacy" class="[^"]*tap-target[^"]*">Privacy</a>.*</footer>#s');
})->with('public pages');
