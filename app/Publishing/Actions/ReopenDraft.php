<?php

namespace App\Publishing\Actions;

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\Exceptions\DraftNotReopenable;
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
 *
 * Only new-event drafts can be reopened: an ignored update or cancellation
 * stays ignored (C14). For a discovered draft the source item is locked last
 * (draft → item); reopening is refused while the item has another pending
 * draft (PIPE-06), otherwise the item is pending again with this draft as
 * its current one (D-23).
 */
final class ReopenDraft
{
    public const KIND_REFUSED = 'Only new-event drafts can be reopened.';

    public const NEWER_DRAFT_PENDING = 'This feed item already has a newer draft waiting for review.';

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is not rejected
     * @throws DraftNotReopenable when the draft is not a new-event draft or its
     *                            feed item already has another pending draft
     */
    public function __invoke(EventDraft $draft, User $reviewer): EventDraft
    {
        // Defence in depth beside the panel gate (T-02-14).
        AdminGate::authorize($reviewer, 'Only the admin can review drafts.');

        [$reopened, $from] = DB::transaction(function () use ($draft): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $from = $locked->review_status;

            if ($locked->kind !== DraftKind::New) {
                throw new DraftNotReopenable(self::KIND_REFUSED);
            }

            $from->assertCanTransitionTo(ReviewStatus::PendingReview);

            $item = $locked->source_item_id === null
                ? null
                : SourceItem::query()->whereKey($locked->source_item_id)->lockForUpdate()->first();

            if ($item !== null && EventDraft::query()
                ->where('source_item_id', $item->id)
                ->whereKeyNot($locked->id)
                ->where('review_status', ReviewStatus::PendingReview)
                ->exists()) {
                throw new DraftNotReopenable(self::NEWER_DRAFT_PENDING);
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::PendingReview,
                'reject_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            $item?->forceFill([
                'review_outcome' => 'pending',
                'current_draft_id' => $locked->id,
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
