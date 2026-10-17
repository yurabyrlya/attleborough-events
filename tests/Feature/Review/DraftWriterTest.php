<?php

use App\Enums\EventOrigin;
use App\Enums\ExtractionMethod;
use App\Enums\ReviewStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventDraft;
use App\Review\CategoryAssigner;
use App\Review\Data\DraftData;
use App\Review\DraftWriter;
use App\Review\Exceptions\InvalidReviewTransition;
use Database\Seeders\CategorySeeder;

beforeEach(function () {
    freezeLondon('2026-10-16 12:00');
    $this->seed(CategorySeeder::class);
    $this->community = Category::where('slug', 'community')->firstOrFail();
});

it('creates a pending draft with no reviewer', function () {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Manual,
        extractionMethod: ExtractionMethod::Manual,
        title: 'Craft Fair',
        preferredCategoryId: $this->community->id,
    ));

    expect($draft->review_status)->toBe(ReviewStatus::PendingReview)
        ->and($draft->reviewed_by)->toBeNull()
        ->and($draft->reviewed_at)->toBeNull()
        ->and($draft->event_id)->toBeNull();
});

dataset('preferred category reasons', [
    'admin' => [EventOrigin::Manual, ExtractionMethod::Manual, 'Chosen by admin'],
    'organiser' => [EventOrigin::Submission, ExtractionMethod::Submission, 'Chosen by organiser'],
    'source' => [EventOrigin::Discovered, ExtractionMethod::Manual, 'Source default'],
]);

it('keeps the preferred category and says who chose it', function (EventOrigin $origin, ExtractionMethod $method, string $reason) {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: $origin,
        extractionMethod: $method,
        title: 'Craft Fair',
        preferredCategoryId: $this->community->id,
    ));

    expect($draft->category_id)->toBe($this->community->id)
        ->and($draft->category_reason)->toBe($reason)
        ->and($draft->category_flagged)->toBeFalse();
})->with('preferred category reasons');

it('keeps the organiser\'s pick on a submission for review', function () {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Submission,
        title: 'Craft Fair',
        preferredCategoryId: $this->community->id,
    ));

    expect($draft->submitted_category_id)->toBe($this->community->id);
});

it('falls back to Other, flagged, when the preferred category no longer exists', function () {
    $draft = app(DraftWriter::class)->create(new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Submission,
        title: 'Craft Fair',
        preferredCategoryId: 999_999,
    ));

    expect($draft->category->slug)->toBe('other')
        ->and($draft->category_reason)->toBe('No match → Other')
        ->and($draft->category_flagged)->toBeTrue()
        ->and($draft->submitted_category_id)->toBeNull();
});

it('updates a pending draft\'s content and recomputes its category', function () {
    $draft = EventDraft::factory()->submission()->create(['title' => 'Old title']);

    $updated = app(DraftWriter::class)->update($draft, new DraftData(
        origin: EventOrigin::Submission,
        extractionMethod: ExtractionMethod::Submission,
        title: 'New title',
        preferredCategoryId: $this->community->id,
    ));

    expect($updated->title)->toBe('New title')
        ->and($updated->category_id)->toBe($this->community->id)
        ->and($updated->category_reason)->toBe('Chosen by organiser')
        ->and($updated->review_status)->toBe(ReviewStatus::PendingReview);
});

it('refuses to update a draft that is no longer pending and changes nothing', function (ReviewStatus $status) {
    $draft = $status === ReviewStatus::Approved
        ? EventDraft::factory()->approvedFor(Event::factory()->create())->create(['title' => 'Original'])
        : EventDraft::factory()->create(['title' => 'Original', 'review_status' => $status]);

    expect(fn () => app(DraftWriter::class)->update($draft, new DraftData(
        origin: EventOrigin::Manual,
        extractionMethod: ExtractionMethod::Manual,
        title: 'Rewritten',
    )))->toThrow(InvalidReviewTransition::class);

    expect($draft->refresh()->title)->toBe('Original')
        ->and($draft->review_status)->toBe($status);
})->with([
    'approved' => ReviewStatus::Approved,
    'rejected' => ReviewStatus::Rejected,
]);

it('normalises labels for comparison: NFC, lower case, single spaces', function () {
    expect(CategoryAssigner::normalise("  Cafe\u{0301}   MUSIC\tNight "))->toBe("caf\u{00E9} music night");
});
