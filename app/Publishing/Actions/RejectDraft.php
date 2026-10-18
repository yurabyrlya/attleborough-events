<?php

namespace App\Publishing\Actions;

use App\Enums\DraftKind;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rejects a pending draft with an optional preset reason and an optional
 * note (REV-04, D-11). Both may be empty; a blank note is stored as null.
 *
 * Admin-only, checked before the transaction opens. Re-reads the draft with
 * SELECT ... FOR UPDATE and asserts pending_review -> rejected on that fresh
 * status, so a stale page cannot reject an approved draft. Writes review
 * state only. Sends nothing to the organiser (D-02).
 *
 * A discovered new-event draft also marks its source item rejected and moves
 * the item's baseline to the current payload, so later runs do not re-draft
 * it unless its facts change or the admin reopens it (D-23). The item is
 * locked last (draft → item). Review CR-03: an item already linked to an
 * event (e.g. an old draft rejected again after a later one was approved)
 * is left alone: its accepted baseline belongs to the published event and
 * moving it would silently drop a feed change the event never received.
 *
 * A rejected draft is purged after the published retention (D-12): see
 * EventDraft::prunable().
 */
final class RejectDraft
{
    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     */
    public function __invoke(EventDraft $draft, User $reviewer, ?RejectReason $reason = null, ?string $note = null): EventDraft
    {
        // Defence in depth beside the panel gate (T-02-14).
        AdminGate::authorize($reviewer, 'Only the admin can review drafts.');

        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        [$rejected, $from] = DB::transaction(function () use ($draft, $reviewer, $reason, $note): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $from = $locked->review_status;

            $from->assertCanTransitionTo(ReviewStatus::Rejected);

            $locked->forceFill([
                'review_status' => ReviewStatus::Rejected,
                'reject_reason' => $reason,
                'review_notes' => $note,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            if ($locked->kind === DraftKind::New && $locked->source_item_id !== null) {
                $link = EventSource::query()->where('source_item_id', $locked->source_item_id)->first();
                $item = SourceItem::query()->whereKey($locked->source_item_id)->lockForUpdate()->first();

                if ($link === null) {
                    $item?->forceFill([
                        'review_outcome' => 'rejected',
                        'accepted_hash' => $item->content_hash,
                        'accepted_payload' => $item->payload,
                    ])->save();
                } else {
                    // Review CR-03: the item stays linked. Its baseline is
                    // untouched; an outcome a pre-fix reopen set back to
                    // pending is restored to how it was linked.
                    $item?->forceFill(['review_outcome' => $link->linked_via])->save();
                }
            }

            return [$locked, $from];
        });

        // Ids only: never titles, notes or contact details. After the
        // outermost commit, so a rolled-back rejection is never logged.
        DB::afterCommit(fn () => Log::info('draft rejected', [
            'draft_id' => $rejected->id,
            'from' => $from->value,
            'to' => ReviewStatus::Rejected->value,
            'user_id' => $reviewer->id,
        ]));

        return $rejected->refresh();
    }
}
