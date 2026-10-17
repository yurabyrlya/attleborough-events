<?php

use App\Enums\ReviewStatus;
use App\Review\Exceptions\InvalidReviewTransition;

/*
 * The review state machine. Pure enum logic: every one of the 25 pairs is
 * pinned, including same-to-same, so a stale page or double submit can never
 * decide a draft twice.
 */

dataset('review status pairs', [
    'pending → pending' => [ReviewStatus::PendingReview, ReviewStatus::PendingReview, false],
    'pending → approved' => [ReviewStatus::PendingReview, ReviewStatus::Approved, true],
    'pending → rejected' => [ReviewStatus::PendingReview, ReviewStatus::Rejected, true],
    'pending → duplicate' => [ReviewStatus::PendingReview, ReviewStatus::Duplicate, true],
    'pending → superseded' => [ReviewStatus::PendingReview, ReviewStatus::Superseded, true],
    'approved → pending' => [ReviewStatus::Approved, ReviewStatus::PendingReview, false],
    'approved → approved' => [ReviewStatus::Approved, ReviewStatus::Approved, false],
    'approved → rejected' => [ReviewStatus::Approved, ReviewStatus::Rejected, false],
    'approved → duplicate' => [ReviewStatus::Approved, ReviewStatus::Duplicate, false],
    'approved → superseded' => [ReviewStatus::Approved, ReviewStatus::Superseded, false],
    'rejected → pending' => [ReviewStatus::Rejected, ReviewStatus::PendingReview, true],
    'rejected → approved' => [ReviewStatus::Rejected, ReviewStatus::Approved, false],
    'rejected → rejected' => [ReviewStatus::Rejected, ReviewStatus::Rejected, false],
    'rejected → duplicate' => [ReviewStatus::Rejected, ReviewStatus::Duplicate, false],
    'rejected → superseded' => [ReviewStatus::Rejected, ReviewStatus::Superseded, false],
    'duplicate → pending' => [ReviewStatus::Duplicate, ReviewStatus::PendingReview, false],
    'duplicate → approved' => [ReviewStatus::Duplicate, ReviewStatus::Approved, false],
    'duplicate → rejected' => [ReviewStatus::Duplicate, ReviewStatus::Rejected, false],
    'duplicate → duplicate' => [ReviewStatus::Duplicate, ReviewStatus::Duplicate, false],
    'duplicate → superseded' => [ReviewStatus::Duplicate, ReviewStatus::Superseded, false],
    'superseded → pending' => [ReviewStatus::Superseded, ReviewStatus::PendingReview, false],
    'superseded → approved' => [ReviewStatus::Superseded, ReviewStatus::Approved, false],
    'superseded → rejected' => [ReviewStatus::Superseded, ReviewStatus::Rejected, false],
    'superseded → duplicate' => [ReviewStatus::Superseded, ReviewStatus::Duplicate, false],
    'superseded → superseded' => [ReviewStatus::Superseded, ReviewStatus::Superseded, false],
]);

it('allows exactly the review status transitions in the machine table', function (ReviewStatus $from, ReviewStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);

    if ($allowed) {
        $from->assertCanTransitionTo($to);
    } else {
        expect(fn () => $from->assertCanTransitionTo($to))->toThrow(InvalidReviewTransition::class);
    }
})->with('review status pairs');

it('names both statuses in the refused transition and exposes them', function () {
    try {
        ReviewStatus::Approved->assertCanTransitionTo(ReviewStatus::Approved);
        $this->fail('Expected InvalidReviewTransition');
    } catch (InvalidReviewTransition $e) {
        expect($e->from)->toBe(ReviewStatus::Approved)
            ->and($e->to)->toBe(ReviewStatus::Approved)
            ->and($e->getMessage())->toContain('approved → approved');
    }
});
