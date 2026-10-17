<?php

use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Enums\QualityBand;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Actions\ReviewActions;
use App\Filament\Resources\EventDrafts\EventDraftResource;
use App\Filament\Resources\EventDrafts\Pages\ListEventDrafts;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Filament\Resources\Events\EventResource;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Models\Venue;
use App\Publishing\Actions\ApproveDraft;
use App\Publishing\Actions\ChangeEventStatus;
use App\Publishing\Actions\PublishEvent;
use App\Publishing\Actions\RejectDraft;
use App\Publishing\Data\PublishableEventData;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * REV-01 draft detail, REV-04 reject / reopen, raw content, stale clicks and
 * escaping on the review page.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email'), 'name' => 'Sam Admin']);
    actingAs($this->admin);

    $this->page = fn (EventDraft $draft) => livewire(ReviewEventDraft::class, ['record' => $draft->id]);

    $this->publish = fn (string $title): Event => app(PublishEvent::class)(new PublishableEventData(
        title: $title,
        description: 'Already on the calendar.',
        categoryId: Category::factory()->create()->id,
        venueId: Venue::factory()->create()->id,
        startDate: '2026-10-24',
        startTime: '19:30:00',
        organiserContact: 'organiser@example.test',
    ), $this->admin, EventOrigin::Manual);

    $this->submission = fn (array $overrides = []): EventDraft => EventDraft::factory()
        ->submission()
        ->freeTextVenue()
        ->scored(QualityBand::Medium, 70, ["Venue not recognised: 'The Old Barn'", 'No end time'])
        ->create(array_merge([
            'title' => 'Harvest Supper',
            'description' => 'Soup, bread and a raffle.',
            'category_id' => Category::factory()->create(['name' => 'Other', 'slug' => 'other'])->id,
            'category_reason' => 'No match → Other',
            'category_flagged' => true,
            'organiser_name' => 'Jo Bloggs',
            'organiser_contact' => 'jo@example.test',
            'organiser_website' => 'https://www.harvest.example/supper',
            'contact_public' => false,
            'source_url' => 'https://www.facebook.com/events/123',
            'raw_payload' => ['title' => 'Harvest Supper', 'venue_name' => 'The Old Barn'],
        ], $overrides));
});

it('shows every REV-01 item for a submission draft', function () {
    Venue::factory()->create(['name' => 'Connaught Hall', 'postcode' => 'NR17 2HA']);
    $draft = ($this->submission)(['venue_name' => 'Connaught Hal']);

    ($this->page)($draft)
        ->assertSee('New event')
        ->assertSee('Pending review')
        ->assertSee($draft->reference)
        ->assertSeeInOrder(['Medium', '70/100', "Venue not recognised: 'The Old Barn'", 'No end time'])
        ->assertSee('Harvest Supper')
        ->assertSee('Soup, bread and a raffle.')
        ->assertSee('Sat 24 Oct, 7:30pm')
        ->assertSee('One-off')
        ->assertSee('Not confirmed')
        ->assertSee('Organiser typed: Connaught Hal, 1 Church Street, NR17 2AA')
        ->assertSee('Suggested: Connaught Hall, matched by similar name')
        ->assertSee('Jo Bloggs')
        ->assertSee('jo@example.test')
        ->assertSee('harvest.example')
        ->assertSeeInOrder(['Contact shown publicly', 'No'])
        ->assertSeeInOrder(['Source', 'Public submission'])
        ->assertSee('facebook.com')
        ->assertSeeHtml('rel="nofollow noopener noreferrer"')
        ->assertSeeInOrder(['Extraction method', 'Organiser form'])
        ->assertSeeInOrder(['Other', 'No match → Other', 'Check category'])
        ->assertSeeInOrder(['Parse errors', 'None'])
        ->assertSee('No duplicate found')
        ->assertDontSee('Organiser says it repeats');
});

it('suggests an exact venue match by name without confirming it', function () {
    Venue::factory()->create(['name' => 'Connaught Hall']);
    $draft = ($this->submission)(['venue_name' => 'connaught hall']);

    ($this->page)($draft)
        ->assertSee('Not confirmed')
        ->assertSee('Suggested: Connaught Hall, matched by name')
        ->assertSee('Pick or create a venue.')
        ->assertActionDisabled('approve');

    expect($draft->refresh()->venue_id)->toBeNull();
});

it('shows the confirmed venue and the typed text as Organiser typed', function () {
    $venue = Venue::factory()->create(['name' => 'Connaught Hall']);
    $draft = EventDraft::factory()->create(['venue_id' => $venue->id, 'venue_name' => 'The Hall on Queens Square']);

    ($this->page)($draft)
        ->assertSee('Connaught Hall')
        ->assertDontSee('Not confirmed')
        ->assertDontSee('Suggested:')
        ->assertSee('Organiser typed: The Hall on Queens Square');
});

it('shows the repeat rule label for a recurring draft', function () {
    $draft = EventDraft::factory()->recurring('FREQ=WEEKLY;BYDAY=SA', '2026-10-24', '19:30:00')->create();

    ($this->page)($draft)
        ->assertSee('Weekly on Saturday')
        ->assertDontSee('One-off');
});

it("says when a stored repeat rule can't be shown", function () {
    $draft = EventDraft::factory()->recurring('FREQ=DAILY;INTERVAL=3', '2026-10-24', '19:30:00')->create();

    ($this->page)($draft)->assertSee("Repeat rule can't be shown");
});

it('shows the organiser repeats text prominently', function () {
    $draft = EventDraft::factory()->withRepeatsText('Every other Tuesday')->create();

    ($this->page)($draft)
        ->assertSee("Organiser says it repeats: 'Every other Tuesday'")
        ->assertSee('One-off');
});

it('links a possible duplicate to its admin page, even when the event is hidden', function () {
    $event = ($this->publish)('Harvest Supper 2026');
    app(ChangeEventStatus::class)($event, EventStatus::Hidden, $this->admin);
    $draft = EventDraft::factory()->duplicateOf($event)->create();

    ($this->page)($draft)
        ->assertSee('Possible duplicate of Harvest Supper 2026')
        ->assertSeeHtml('href="'.e(EventResource::getUrl('edit', ['record' => $event])).'"')
        ->assertDontSee('No duplicate found');
});

it('lists parse errors', function () {
    $draft = EventDraft::factory()->withParseErrors(['Start time "half seven" not understood'])->create();

    ($this->page)($draft)
        ->assertSee('Start time "half seven" not understood');
});

it('shows what the organiser sent in the raw content modal', function () {
    $draft = ($this->submission)();

    ($this->page)($draft)
        ->mountAction('rawContent')
        ->assertMountedActionModalSee(['What the organiser sent', 'venue_name', 'The Old Barn']);
});

it('says a manual draft has no raw content', function () {
    $draft = EventDraft::factory()->manual()->create();

    ($this->page)($draft)
        ->assertSee('No raw content: entered by admin')
        ->mountAction('rawContent')
        ->assertMountedActionModalSee('No raw content: entered by admin');
});

it('rejects with a reason and a note', function () {
    $draft = ($this->submission)();

    ($this->page)($draft)
        ->assertActionVisible('reject')
        ->callAction('reject', data: ['reject_reason' => 'spam', 'review_notes' => 'Link farm'])
        ->assertNotified('Draft rejected')
        ->assertRedirect(EventDraftResource::getUrl('index'));

    $draft->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::Rejected)
        ->and($draft->reject_reason)->toBe(RejectReason::Spam)
        ->and($draft->review_notes)->toBe('Link farm')
        ->and($draft->reviewed_by)->toBe($this->admin->id);
});

it('rejects with no reason and no note', function () {
    $draft = ($this->submission)();

    ($this->page)($draft)
        ->callAction('reject')
        ->assertNotified('Draft rejected');

    $draft->refresh();

    expect($draft->review_status)->toBe(ReviewStatus::Rejected)
        ->and($draft->reject_reason)->toBeNull()
        ->and($draft->review_notes)->toBeNull();
});

it('names the dialog buttons Reject draft and Keep as is', function () {
    $reject = ReviewActions::reject();
    $reopen = ReviewActions::reopen();
    $approve = ReviewActions::approve();

    expect($reject->getModalSubmitActionLabel())->toBe('Reject draft')
        ->and($reject->getModalCancelActionLabel())->toBe('Keep as is')
        ->and($reopen->getModalCancelActionLabel())->toBe('Keep as is')
        ->and($approve->getModalCancelActionLabel())->toBe('Keep as is');
});

it('rejects and reopens from the table', function () {
    $draft = ($this->submission)();

    livewire(ListEventDrafts::class)
        ->callAction(TestAction::make('reject')->table($draft), data: ['reject_reason' => 'out_of_area'])
        ->assertNotified('Draft rejected');

    expect($draft->refresh()->reject_reason)->toBe(RejectReason::OutOfArea);

    livewire(ListEventDrafts::class)
        ->assertActionHidden(TestAction::make('reject')->table($draft))
        ->callAction(TestAction::make('reopen')->table($draft))
        ->assertNotified('Draft reopened');

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('shows a rejected draft with its reason, note and a Reopen action that returns it to pending', function () {
    $draft = ($this->submission)();
    app(RejectDraft::class)($draft, $this->admin, RejectReason::NotAnEvent, 'Advert for a shop');

    ($this->page)($draft)
        ->assertSee('Rejected')
        ->assertSee('Not an event')
        ->assertSee('Advert for a shop')
        ->assertSee('Sam Admin')
        ->assertDontSee('Before you can publish')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionVisible('reopen')
        ->callAction('reopen')
        ->assertNotified('Draft reopened')
        ->assertActionHidden('reopen')
        ->assertActionVisible('reject');

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview);
});

it('links an approved draft to its published event', function () {
    $draft = EventDraft::factory()->create(['title' => 'Quiz Night']);
    $event = app(ApproveDraft::class)($draft, $this->admin);

    ($this->page)($draft)
        ->assertSee('Approved')
        ->assertSeeHtml('href="'.e(EventResource::getUrl('edit', ['record' => $event])).'"')
        ->assertActionHidden('approve')
        ->assertActionHidden('reject')
        ->assertActionHidden('reopen');
});

it('reports a stale approve as already reviewed and creates no second event', function () {
    $draft = EventDraft::factory()->create();
    $page = ($this->page)($draft);

    app(ApproveDraft::class)($draft, $this->admin);

    $page->callAction('approve')
        ->assertNotified(ReviewActions::ALREADY_REVIEWED)
        ->assertNoRedirect();

    expect(Event::count())->toBe(1);
});

it('reports a stale reject as already reviewed and leaves the draft approved', function () {
    $draft = EventDraft::factory()->create();
    $page = ($this->page)($draft);

    app(ApproveDraft::class)($draft, $this->admin);

    $page->callAction('reject', data: ['reject_reason' => 'spam'])
        ->assertNotified(ReviewActions::ALREADY_REVIEWED);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and($draft->reject_reason)->toBeNull()
        ->and(Event::count())->toBe(1);
});

it('escapes organiser text on the page and in the raw content modal', function (string $field) {
    $script = '<script>alert(1)</script>';
    $draft = ($this->submission)([
        $field => $field === 'raw_payload' ? ['title' => $script, $script => 'x'] : $script,
    ]);

    $page = ($this->page)($draft)->assertDontSeeHtml($script);

    if ($field !== 'raw_payload') {
        $page->assertSee($script);
    }

    $page->mountAction('rawContent')->assertMountedActionModalDontSeeHtml($script);

    if ($field === 'raw_payload') {
        $page->assertMountedActionModalSee($script);
    }
})->with(['title', 'description', 'repeats_text', 'raw_payload']);

it('escapes the title in notifications after a stale click', function () {
    $draft = EventDraft::factory()->create(['title' => '<script>alert(1)</script>']);
    $page = ($this->page)($draft);
    app(ApproveDraft::class)($draft, $this->admin);

    $page->callAction('approve')->assertDontSeeHtml('<script>alert(1)</script>');
});

it('wraps long unbroken titles', function () {
    $draft = EventDraft::factory()->create(['title' => str_repeat('A', 180)]);

    ($this->page)($draft)
        ->assertSee(str_repeat('A', 180))
        ->assertSeeHtml('break-words');
});

it('shows the received time in London', function () {
    $draft = EventDraft::factory()->create(['created_at' => CarbonImmutable::parse('2026-10-19 21:15', 'Europe/London')->utc()]);

    ($this->page)($draft)->assertSee('Mon 19 Oct 2026, 9:15pm');
});
