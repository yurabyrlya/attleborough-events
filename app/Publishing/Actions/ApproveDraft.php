<?php

namespace App\Publishing\Actions;

use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Data\PublishableEventData;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Approves a draft: the review queue's (and manual entry's, D-07) only path
 * to publication (REV-02).
 *
 * Admin-only, checked before the transaction opens, so a refused call writes
 * nothing. In ONE transaction it re-reads the draft with SELECT ... FOR
 * UPDATE, asserts pending_review -> approved on that fresh status, builds the
 * publishable data from the draft, calls PublishEvent (the only creator of
 * Event rows; its own transaction becomes a savepoint) and stamps the draft
 * approved with the event, reviewer and time. If any step throws, neither the
 * draft change nor any events / event_occurrences row persists.
 */
final class ApproveDraft
{
    public function __construct(private PublishEvent $publish) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     * @throws DraftNotPublishable when the draft lacks what publishing needs
     */
    public function __invoke(EventDraft $draft, User $reviewer): Event
    {
        // Defence in depth beside the panel gate (T-02-01).
        AdminGate::authorize($reviewer, 'Only the admin can approve drafts.');

        [$approved, $event] = DB::transaction(function () use ($draft, $reviewer): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);

            try {
                $data = PublishableEventData::fromDraft($locked);
            } catch (InvalidArgumentException $e) {
                throw new DraftNotPublishable([$e->getMessage()]);
            }

            $event = ($this->publish)($data, $reviewer, $locked->origin);

            $locked->forceFill([
                'review_status' => ReviewStatus::Approved,
                'event_id' => $event->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            return [$locked, $event];
        });

        // Ids only: never titles or contact details. After the outermost
        // commit, so a rolled-back approval is never logged.
        DB::afterCommit(fn () => Log::info('draft approved', [
            'draft_id' => $approved->id,
            'event_id' => $event->id,
            'user_id' => $reviewer->id,
        ]));

        return $event;
    }
}
