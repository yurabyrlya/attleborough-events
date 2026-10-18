<?php

namespace App\Publishing\Actions;

use App\Enums\ReviewStatus;
use App\Ingestion\Data\DuplicateMatch;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\DuplicateDetector;
use App\Models\DuplicateDecision;
use App\Models\EventDraft;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Not a duplicate" (D-13): remembers that a flagged draft is not a
 * duplicate of its suspected match, so the pair is never flagged again.
 *
 * Stores a duplicate_decisions row (decision not_duplicate; subject = the
 * source item when there is one, so the memory survives re-drafts, else
 * the draft; candidate = the flagged event or draft). Then, for an
 * ingested draft, re-runs DuplicateDetector on the item's facts: the
 * warning moves to the next likely match or is cleared. A draft without a
 * source item just has its warning cleared. Review status is unchanged
 * (still pending). A draft with no warning is returned unchanged.
 *
 * Admin-only, checked before the transaction. Draft re-read FOR UPDATE and
 * checked pending on that row. Writes the draft's duplicate columns and
 * the decision only; the quality score does not depend on them. Ids-only
 * log after commit.
 */
final class RecordNotDuplicate
{
    public function __construct(private DuplicateDetector $detector) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     */
    public function __invoke(EventDraft $draft, User $reviewer): EventDraft
    {
        // Defence in depth beside the panel gate (T-03-33).
        AdminGate::authorize($reviewer, 'Only the admin can review drafts.');

        $result = DB::transaction(function () use ($draft, $reviewer): ?array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if ($locked->review_status !== ReviewStatus::PendingReview) {
                throw new InvalidReviewTransition($locked->review_status, ReviewStatus::PendingReview);
            }

            $candidateEventId = $locked->duplicate_of_event_id;
            $candidateDraftId = $candidateEventId === null ? $locked->duplicate_of_draft_id : null;

            if ($candidateEventId === null && $candidateDraftId === null) {
                return null;
            }

            $item = $locked->source_item_id !== null ? SourceItem::query()->find($locked->source_item_id) : null;

            DuplicateDecision::record($item?->id, $locked->id, $candidateEventId, $candidateDraftId, DuplicateDecision::NOT_DUPLICATE, $reviewer);

            $locked->forceFill(DuplicateMatch::columns($this->nextMatch($locked, $item)))->save();

            return [$locked, $candidateEventId, $candidateDraftId];
        });

        if ($result === null) {
            return $draft->refresh();
        }

        [$updated, $candidateEventId, $candidateDraftId] = $result;

        DB::afterCommit(fn () => Log::info('draft marked not duplicate', [
            'draft_id' => $updated->id,
            'candidate_event_id' => $candidateEventId,
            'candidate_draft_id' => $candidateDraftId,
            'next_event_id' => $updated->duplicate_of_event_id,
            'next_draft_id' => $updated->duplicate_of_draft_id,
            'user_id' => $reviewer->id,
        ]));

        return $updated->refresh();
    }

    /**
     * The next likely duplicate of an ingested draft (the decision just
     * stored excludes the old pair), or null.
     */
    private function nextMatch(EventDraft $draft, ?SourceItem $item): ?DuplicateMatch
    {
        if ($item === null || ! is_array($item->payload)) {
            return null;
        }

        $source = Source::query()->find($draft->source_id ?? $item->source_id);

        if ($source === null) {
            return null;
        }

        return $this->detector->find(NormalisedFacts::fromPayload($item->payload), $source, $item, $draft->venue_id);
    }
}
