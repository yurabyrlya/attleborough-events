<?php

namespace App\Publishing\Actions;

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\EventDraft;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Review\CategoryAssigner;
use App\Review\DraftPublishability;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use App\Review\ReviewQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * "Approve all like this" (D-03, EXT-01): approves every pending new draft
 * that came from the same source as $seed, with the same normalised title
 * (CategoryAssigner::normalise: NFC, lower case, whitespace collapsed) and
 * the same venue_id (both null counts as the same), $seed included.
 *
 * Feeds such as the Town Council list each weekly session as its own item,
 * so one draft per item stays workable: the admin reviews one and approves
 * the rest in one click. Matching has no date range; it is capped at LIMIT
 * drafts per call, taken in ReviewQueue order.
 *
 * Each match gets the Phase 2 bulk rules (D-21): the first
 * DraftPublishability::bulkBlockerKeys() key skips it; otherwise it goes
 * through ApproveDraft in its own transaction, which re-checks it. A draft
 * decided in the meantime counts as already_reviewed, one ApproveDraft still
 * refuses as blocked. Nothing here writes a model itself.
 *
 * The caller builds its own notification from the result (RESEARCH F7: a
 * record action has no selection for reportBulkProcessingFailure()).
 */
final class ApproveAllLikeThis
{
    /**
     * The most drafts one click approves.
     */
    public const LIMIT = 200;

    public function __construct(
        private ApproveDraft $approve,
        private DraftPublishability $publishability,
    ) {}

    /**
     * Result: approved = drafts published; total = drafts that matched
     * (capped at LIMIT); skipped = skip key => count, in the order the keys
     * were first met, keys as DraftPublishability::skipMessage() knows them.
     * approved + the skipped counts = total.
     *
     * @return array{approved: int, total: int, skipped: array<string, int>}
     *
     * @throws AuthorizationException when the actor is not the configured admin
     */
    public function __invoke(EventDraft $seed, User $actor): array
    {
        // Defence in depth beside the panel gate and ApproveDraft's own check.
        AdminGate::authorize($actor, 'Only the admin can approve drafts.');

        $drafts = $this->matching($seed);
        $approved = 0;
        $skipped = [];

        foreach ($drafts as $draft) {
            $key = $this->approveOne($draft, $actor);

            if ($key === null) {
                $approved++;
            } else {
                $skipped[$key] = ($skipped[$key] ?? 0) + 1;
            }
        }

        return ['approved' => $approved, 'total' => $drafts->count(), 'skipped' => $skipped];
    }

    /**
     * The drafts a click on $seed would approve, in ReviewQueue order,
     * at most LIMIT. Empty when $seed is not a pending new sourced draft.
     *
     * @return Collection<int, EventDraft>
     */
    public function matching(EventDraft $seed): Collection
    {
        if (! self::applies($seed)) {
            return new Collection;
        }

        $query = EventDraft::query()
            ->where('review_status', ReviewStatus::PendingReview)
            ->where('kind', DraftKind::New)
            ->where('source_id', $seed->source_id)
            // The SQL twin of CategoryAssigner::normalise(): NFC, lower case,
            // whitespace runs collapsed to one space, trimmed.
            ->whereRaw("btrim(regexp_replace(lower(normalize(title, NFC)), '\\s+', ' ', 'g')) = ?", [CategoryAssigner::normalise((string) $seed->title)]);

        // venue_id IS NOT DISTINCT FROM the seed's venue, without binding an
        // untyped null.
        if ($seed->venue_id === null) {
            $query->whereNull('venue_id');
        } else {
            $query->where('venue_id', $seed->venue_id);
        }

        return ReviewQueue::applyOrder($query)->limit(self::LIMIT)->get();
    }

    /**
     * Whether "Approve all like this" applies to $draft: pending, a new
     * event, from a source, with a title.
     */
    public static function applies(EventDraft $draft): bool
    {
        return $draft->review_status === ReviewStatus::PendingReview
            && $draft->kind === DraftKind::New
            && $draft->source_id !== null
            && trim((string) $draft->title) !== '';
    }

    /**
     * Approve one match; the skip key, or null when it was approved.
     */
    private function approveOne(EventDraft $draft, User $actor): ?string
    {
        if ($draft->review_status !== ReviewStatus::PendingReview) {
            return 'already_reviewed';
        }

        $keys = $this->publishability->bulkBlockerKeys($draft);

        if ($keys !== []) {
            return $keys[0];
        }

        try {
            ($this->approve)($draft, $actor);
        } catch (InvalidReviewTransition|ModelNotFoundException) {
            return 'already_reviewed';
        } catch (DraftNotPublishable) {
            return 'blocked';
        }

        return null;
    }
}
