<?php

namespace App\Publishing\Actions;

use App\Enums\OccurrenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Data\PublishableEventData;
use App\Review\DraftPublishability;
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
 * UPDATE, asserts pending_review -> approved on that fresh status, refuses it
 * with DraftPublishability's blockers (the same check the Approve button
 * uses, re-run here whatever the page showed), builds the publishable data, calls PublishEvent (the only creator of
 * Event rows; its own transaction becomes a savepoint) and stamps the draft
 * approved with the event, reviewer and time. If any step throws, neither the
 * draft change nor any events / event_occurrences row persists.
 *
 * A discovered draft (source_item_id set) is also, in the same transaction:
 * linked to its source through LinkEventSource (linked_via approved, LEG-04,
 * D-12, D-25), and its source item gets the accepted baseline
 * (accepted_hash = content_hash, accepted_payload = payload, review_outcome
 * approved, PIPE-06). A series' cancelled overrides
 * (raw_payload cancelled_keys, the dates the admin saw on the draft) mark
 * those occurrences Cancelled through ChangeOccurrenceStatus, so the dates
 * stay visible as "Cancelled" (D-08, D-19); only dates already inside the
 * occurrence horizon exist to be cancelled. Lock order: draft → event
 * (PublishEvent, ChangeOccurrenceStatus, LinkEventSource) → source item
 * last. Manual and submission drafts are untouched by this.
 */
final class ApproveDraft
{
    public function __construct(
        private PublishEvent $publish,
        private DraftPublishability $publishability,
        private LinkEventSource $link,
        private ChangeOccurrenceStatus $occurrenceStatus,
    ) {}

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

            $blockers = $this->publishability->blockers($locked);

            if ($blockers !== []) {
                throw new DraftNotPublishable($blockers);
            }

            try {
                $data = PublishableEventData::fromDraft($locked);
            } catch (InvalidArgumentException $e) {
                throw new DraftNotPublishable([$e->getMessage()]);
            }

            $event = ($this->publish)($data, $reviewer, $locked->origin);

            if ($locked->source_item_id !== null) {
                $this->cancelOverrides($locked, $event, $reviewer);
                $this->linkSource($locked, $event, $reviewer);
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::Approved,
                'event_id' => $event->id,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            if ($locked->source_item_id !== null) {
                $this->acceptBaseline($locked->source_item_id);
            }

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

    /**
     * D-08/D-19: the feed's cancelled dates of a series publish as Cancelled
     * occurrences (never deleted), so residents still see "Cancelled".
     */
    private function cancelOverrides(EventDraft $draft, Event $event, User $reviewer): void
    {
        $keys = $draft->raw_payload['cancelled_keys'] ?? [];

        if ($event->recurrence_rule === null || ! is_array($keys) || $keys === []) {
            return;
        }

        $occurrences = EventOccurrence::query()
            ->where('event_id', $event->id)
            ->whereIn('recurrence_key', array_values(array_filter($keys, is_string(...))))
            ->where('status', OccurrenceStatus::Scheduled)
            ->orderBy('recurrence_key')
            ->get();

        foreach ($occurrences as $occurrence) {
            ($this->occurrenceStatus)($occurrence, OccurrenceStatus::Cancelled, $reviewer);
        }
    }

    /**
     * LEG-04: a discovered event always knows the source it came from.
     */
    private function linkSource(EventDraft $draft, Event $event, User $reviewer): void
    {
        $item = SourceItem::query()->findOrFail($draft->source_item_id);
        $source = Source::query()->findOrFail($draft->source_id ?? $item->source_id);

        ($this->link)($event, $source, $item, EventSource::VIA_APPROVED, $reviewer);
    }

    /**
     * PIPE-06: what the admin approved becomes the item's baseline, so the
     * next run only drafts a change to these facts. Item locked last.
     */
    private function acceptBaseline(int $sourceItemId): void
    {
        $item = SourceItem::query()->whereKey($sourceItemId)->lockForUpdate()->firstOrFail();

        $item->forceFill([
            'accepted_hash' => $item->content_hash,
            'accepted_payload' => $item->payload,
            'review_outcome' => 'approved',
        ])->save();
    }
}
