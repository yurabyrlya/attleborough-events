<?php

namespace App\Publishing\Actions;

use App\Enums\DraftKind;
use App\Enums\RejectReason;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\SourceBaseline;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ignores a source change (REV-06, REV-07): the pending update or
 * cancellation draft is rejected with reject_reason "ignored"
 * (RejectReason::Ignored, A7) and the item's accepted baseline moves, so
 * the same change is not raised again. Writes review state and the
 * baseline only; the published event is never touched.
 *
 * - Update: the baseline moves to the source's current payload.
 * - Cancellation: only the cancelled part moves (SourceBaseline::
 *   moveCancelledPart), so a fact change in the same snapshot is raised by
 *   the next run, and an ignored date is never added to
 *   events.cancelled_dates (it stays Scheduled when the sync reaches it).
 *
 * Admin-only, checked before the transaction. Re-reads the draft FOR
 * UPDATE and checks the transition and the kind on that row, so Apply and
 * Ignore on the same draft serialise and exactly one wins. Lock order:
 * draft → source item. Ids-only log after commit.
 */
final class IgnoreSourceChange
{
    public const string WRONG_KIND = 'Only source update and cancellation drafts can be ignored.';

    public function __construct(private SourceBaseline $baseline) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     * @throws DraftNotPublishable when the draft is not a source change
     */
    public function __invoke(EventDraft $draft, User $reviewer): EventDraft
    {
        // Defence in depth beside the panel gate (T-03-28).
        AdminGate::authorize($reviewer, 'Only the admin can ignore source changes.');

        $ignored = DB::transaction(function () use ($draft, $reviewer): EventDraft {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $locked->review_status->assertCanTransitionTo(ReviewStatus::Rejected);

            if (! in_array($locked->kind, [DraftKind::Update, DraftKind::Cancellation], true)) {
                throw new DraftNotPublishable([self::WRONG_KIND]);
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::Rejected,
                'reject_reason' => RejectReason::Ignored,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            if ($locked->source_item_id !== null) {
                $item = SourceItem::query()->whereKey($locked->source_item_id)->lockForUpdate()->first();

                if ($item !== null) {
                    $locked->kind === DraftKind::Update
                        ? $this->baseline->moveToCurrent($item)
                        : $this->baseline->moveCancelledPart($item);
                }
            }

            return $locked;
        });

        // Ids only: never titles or contact details. After the outermost
        // commit, so a rolled-back ignore is never logged.
        DB::afterCommit(fn () => Log::info('source change ignored', [
            'draft_id' => $ignored->id,
            'kind' => $ignored->kind->value,
            'event_id' => $ignored->event_id,
            'user_id' => $reviewer->id,
        ]));

        return $ignored->refresh();
    }
}
