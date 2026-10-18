<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Filament\Resources\EventDrafts\Pages\EditEventDraft;
use App\Filament\Resources\EventDrafts\Pages\ReviewEventDraft;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\User;
use App\Publishing\Actions\ApproveDraft;
use App\Review\DiscoveredDescription;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/*
 * Verification VER-01 (PIPE-09, D-15, D-17): a discovered event's public
 * description is at most 200 characters with no phone number or email,
 * also after the admin edits the excerpt. The edit page refuses such a
 * save, and single approval lists it under "Before you can publish".
 * Manual entries and public submissions keep their own rules.
 */
beforeEach(function () {
    freezeLondon('2026-10-20 10:00');

    $this->admin = User::factory()->create(['email' => config('app.admin_email')]);
    actingAs($this->admin);
});

/**
 * A discovered draft attributed to a source, as the pipeline writes it.
 *
 * @param  array<string, mixed>  $attributes
 */
function discoveredDescriptionDraft(array $attributes = []): EventDraft
{
    return EventDraft::factory()->create([
        'origin' => EventOrigin::Discovered,
        'extraction_method' => ExtractionMethod::Ics,
        'source_id' => Source::factory()->ics()->active()->create()->id,
        'source_url' => 'https://example.org/events/quiz',
        'organiser_contact' => null,
        'description' => 'Quiz night in aid of the scouts.',
        ...$attributes,
    ]);
}

function discoveredText(int $graphemes): string
{
    return str_repeat('a', $graphemes);
}

it('shows the 200-character help on a discovered draft only', function () {
    livewire(EditEventDraft::class, ['record' => discoveredDescriptionDraft()->id])
        ->assertSee(DiscoveredDescription::HELP);

    livewire(EditEventDraft::class, ['record' => EventDraft::factory()->create()->id])
        ->assertDontSee(DiscoveredDescription::HELP);
});

it('refuses to save a discovered description over 200 characters and saves one of 200', function () {
    $draft = discoveredDescriptionDraft();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => discoveredText(201)])
        ->call('save')
        ->assertHasFormErrors(['description' => 'Discovered events can show up to 200 characters. Shorten the description (it has 201).']);

    expect($draft->refresh()->description)->toBe('Quiz night in aid of the scouts.');

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => discoveredText(200)])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh()->description)->toBe(discoveredText(200));
});

it('counts characters as the excerpt does, so 200 emoji with skin tones still fit', function () {
    // 200 graphemes, 400 code points: what ExcerptBuilder may legitimately write.
    $draft = discoveredDescriptionDraft();
    $emoji = str_repeat('👍🏽', 200);

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => $emoji])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($draft->refresh()->description)->toBe($emoji)
        ->and(app(DraftPublishability::class)->blockers($draft))->toBe([]);
});

it('refuses to save a discovered description with a phone number or email', function (string $description) {
    $draft = discoveredDescriptionDraft();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => $description])
        ->call('save')
        ->assertHasFormErrors(['description' => 'Remove the phone number or email from the description. Discovered events never show contacts from feeds.']);

    expect($draft->refresh()->description)->toBe('Quiz night in aid of the scouts.');
})->with([
    'UK number' => ['Quiz night. Call 01953 452300 to book.'],
    'bracketed area code' => ['Quiz night. Box office (01953) 452300.'],
    'local number after a phone word' => ['Quiz night. Ring us on 452 300.'],
    'email' => ['Quiz night. Book with jo@example.org.'],
]);

it('saves and publishes harmless numbers on a discovered draft (strict contact rule)', function () {
    $draft = discoveredDescriptionDraft();
    $text = 'Seasons 2026-2027. Open 0930-1630, doors 7.30pm, tickets £5.00. Bring your phone.';

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => $text])
        ->call('saveAndApprove')
        ->assertHasNoFormErrors()
        ->assertNotified('Published');

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and(Event::query()->sole()->description)->toBe($text);
});

it('refuses Save & approve of a long discovered description and publishes nothing', function () {
    $draft = discoveredDescriptionDraft();

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->fillForm(['description' => discoveredText(201)])
        ->call('saveAndApprove')
        ->assertHasFormErrors(['description']);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->description)->toBe('Quiz night in aid of the scouts.')
        ->and(Event::count())->toBe(0);
});

it('keeps the manual and submission rules: long text and contacts save and publish', function (string $state) {
    $draft = EventDraft::factory()->{$state}()->create();
    $text = discoveredText(150).' Call 01953 452300 or email jo@example.org to book.';

    livewire(EditEventDraft::class, ['record' => $draft->id])
        ->assertDontSee(DiscoveredDescription::HELP)
        ->fillForm(['description' => $text])
        ->call('saveAndApprove')
        ->assertHasNoFormErrors()
        ->assertNotified('Published');

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Approved)
        ->and(mb_strlen($text))->toBeGreaterThan(200);
})->with(['manual' => 'manual', 'submission' => 'submission']);

it('lists the description blockers and disables Approve for a discovered draft', function (string $description, string $blocker) {
    $draft = discoveredDescriptionDraft(['description' => $description]);

    livewire(ReviewEventDraft::class, ['record' => $draft->id])
        ->assertSee('Before you can publish')
        ->assertSee($blocker)
        ->assertActionDisabled('approve');

    expect(fn () => app(ApproveDraft::class)($draft, $this->admin))
        ->toThrow(fn (DraftNotPublishable $e) => expect($e->blockers)->toBe([$blocker]));

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::PendingReview)
        ->and(Event::count())->toBe(0);
})->with([
    'too long' => [discoveredText(201), DiscoveredDescription::TOO_LONG],
    'phone number' => ['Quiz night. Call 01953 452300 to book.', DiscoveredDescription::HAS_CONTACT],
    'email' => ['Quiz night. Book with jo@example.org.', DiscoveredDescription::HAS_CONTACT],
]);

it('lists both description blockers in order', function () {
    $draft = discoveredDescriptionDraft(['description' => discoveredText(201).' Tel 01953 452300']);

    expect(app(DraftPublishability::class)->blockers($draft))
        ->toBe([DiscoveredDescription::TOO_LONG, DiscoveredDescription::HAS_CONTACT]);
});

it('does not block a manual or submitted draft with a long description or a contact', function (string $state) {
    $draft = EventDraft::factory()->{$state}()->create(['description' => discoveredText(201).' Call 01953 452300']);

    expect(app(DraftPublishability::class)->blockers($draft))->toBe([]);

    app(ApproveDraft::class)($draft, $this->admin);

    expect($draft->refresh()->review_status)->toBe(ReviewStatus::Approved);
})->with(['manual' => 'manual', 'submission' => 'submission']);
