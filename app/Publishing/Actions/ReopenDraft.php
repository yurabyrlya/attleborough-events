<?php

namespace App\Publishing\Actions;

use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Undo for a rejection (D-12): returns a rejected draft to pending_review.
 *
 * Clears reject_reason, reviewed_by and reviewed_at (so the draft leaves the
 * prune window) and keeps review_notes, so the admin still sees why it was
 * rejected. Admin-only; asserts rejected -> pending_review on the locked row.
 * Sends nothing to the organiser (D-02).
 */
final class ReopenDraft
{
    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is not rejected
     */
    public function __invoke(EventDraft $draft, User $reviewer): EventDraft
    {
        // Defence in depth beside the panel gate (T-02-14).
        AdminGate::authorize($reviewer, 'Only the admin can review drafts.');

        [$reopened, $from] = DB::transaction(function () use ($draft): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $from = $locked->review_status;

            $from->assertCanTransitionTo(ReviewStatus::PendingReview);

            $locked->forceFill([
                'review_status' => ReviewStatus::PendingReview,
                'reject_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            return [$locked, $from];
        });

        // Ids only: never titles, notes or contact details.
        DB::afterCommit(fn () => Log::info('draft reopened', [
            'draft_id' => $reopened->id,
            'from' => $from->value,
            'to' => ReviewStatus::PendingReview->value,
            'user_id' => $reviewer->id,
        ]));

        return $reopened->refresh();
    }
}
