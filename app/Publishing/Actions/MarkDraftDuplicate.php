<?php

namespace App\Publishing\Actions;

use App\Enums\DraftKind;
use App\Enums\ReviewStatus;
use App\Models\DuplicateDecision;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventSource;
use App\Models\Source;
use App\Models\SourceItem;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\SourceBaseline;
use App\Review\Exceptions\DraftNotPublishable;
use App\Review\Exceptions\InvalidReviewTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Marks a pending new-event draft as a duplicate of a published event
 * (REV-05, D-10, D-11, D-13).
 *
 * The existing event's published details win: nothing is published and
 * the event is never written (D-10). The draft becomes review_status
 * duplicate with duplicate_of_event_id = the event; event_id stays null,
 * because event_drafts_one_new_per_event allows one New draft per event
 * (the approved one, F9). The decision is remembered in
 * duplicate_decisions (subject: the source item, else the draft).
 *
 * A sourced draft also links its feed item to the event through
 * LinkEventSource (linked_via duplicate; primary only when the event has
 * no primary yet, or by the D-25 organiser-site rule) and the item gets
 * review_outcome duplicate and the accepted baseline = its current payload,
 * so later changes or cancellations of that item raise update /
 * cancellation drafts for the event (D-11). Manual and submission drafts
 * get no source link.
 *
 * The target may be passed as a draft: an approved draft stands for its
 * event; a pending one is refused ("Approve or reject the other draft
 * first."), since a duplicate needs a published event to point at.
 *
 * Admin-only, checked before the transaction. Draft re-read FOR UPDATE and
 * the transition checked on that row. Lock order: draft → event
 * (LinkEventSource) → source item last. Ids-only log after commit.
 */
final class MarkDraftDuplicate
{
    public const string WRONG_KIND = 'Only new-event drafts can be marked as duplicates.';

    public const string TARGET_PENDING = 'Approve or reject the other draft first.';

    public const string TARGET_NOT_PUBLISHED = 'The other draft was not published, so there is no event to link to.';

    public const string SAME_DRAFT = 'A draft cannot be a duplicate of itself.';

    public function __construct(
        private LinkEventSource $link,
        private SourceBaseline $baseline,
    ) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     * @throws DraftNotPublishable when the draft is not a new-event draft or the target is not a published event
     * @throws DraftNotPublishable when the feed item is already linked to an event (review CR-03)
     */
    public function __invoke(EventDraft $draft, Event|EventDraft $of, User $reviewer): EventDraft
    {
        // Defence in depth beside the panel gate (T-03-33).
        AdminGate::authorize($reviewer, 'Only the admin can review drafts.');

        [$marked, $eventId, $linkId] = DB::transaction(function () use ($draft, $of, $reviewer): array {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $locked->review_status->assertCanTransitionTo(ReviewStatus::Duplicate);

            if ($locked->kind !== DraftKind::New) {
                throw new DraftNotPublishable([self::WRONG_KIND]);
            }

            $event = $this->targetEvent($locked, $of);

            $sameMatch = $locked->duplicate_of_event_id === $event->id;
            $linkId = null;
            $item = null;

            if ($locked->source_item_id !== null) {
                $item = SourceItem::query()->find($locked->source_item_id);
            }

            if ($item !== null) {
                $source = Source::query()->findOrFail($locked->source_id ?? $item->source_id);
                $linkId = $this->linkItem($event, $source, $item, $reviewer);
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::Duplicate,
                'duplicate_of_event_id' => $event->id,
                'duplicate_of_draft_id' => null,
                // The warning's reason and score only describe this event.
                'duplicate_reason' => $sameMatch ? $locked->duplicate_reason : null,
                'duplicate_score' => $sameMatch ? $locked->duplicate_score : null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            DuplicateDecision::record($item?->id, $locked->id, $event->id, null, DuplicateDecision::DUPLICATE, $reviewer);

            if ($item !== null) {
                // Locked last (draft → event → item).
                $lockedItem = SourceItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $lockedItem->forceFill(['review_outcome' => 'duplicate']);
                $this->baseline->moveToCurrent($lockedItem);
            }

            return [$locked, $event->id, $linkId];
        });

        // Ids only. After the outermost commit, so a rolled-back decision is
        // never logged.
        DB::afterCommit(fn () => Log::info('draft marked duplicate', [
            'draft_id' => $marked->id,
            'event_id' => $eventId,
            'event_source_id' => $linkId,
            'user_id' => $reviewer->id,
        ]));

        return $marked->refresh();
    }

    /**
     * Review CR-03: an item already linked (to this or another event) is a
     * plain refusal, never a 500.
     *
     * @throws DraftNotPublishable when the feed item is already linked
     */
    private function linkItem(Event $event, Source $source, SourceItem $item, User $reviewer): int
    {
        if (EventSource::linksItem($item->id)) {
            throw new DraftNotPublishable([LinkEventSource::ITEM_ALREADY_PUBLISHED]);
        }

        try {
            return ($this->link)($event, $source, $item, EventSource::VIA_DUPLICATE, $reviewer)->id;
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() === LinkEventSource::ITEM_ALREADY_LINKED) {
                throw new DraftNotPublishable([LinkEventSource::ITEM_ALREADY_PUBLISHED]);
            }

            throw $e;
        }
    }

    /**
     * The published event the draft duplicates.
     *
     * @throws DraftNotPublishable when a draft target is pending, unpublished or the draft itself
     */
    private function targetEvent(EventDraft $locked, Event|EventDraft $of): Event
    {
        if ($of instanceof Event) {
            return Event::query()->findOrFail($of->id);
        }

        if ($of->id === $locked->id) {
            throw new DraftNotPublishable([self::SAME_DRAFT]);
        }

        $target = EventDraft::query()->findOrFail($of->id);

        if ($target->review_status === ReviewStatus::PendingReview) {
            throw new DraftNotPublishable([self::TARGET_PENDING]);
        }

        if ($target->review_status !== ReviewStatus::Approved || $target->event_id === null) {
            throw new DraftNotPublishable([self::TARGET_NOT_PUBLISHED]);
        }

        return Event::query()->findOrFail($target->event_id);
    }
}
