<?php

namespace App\Publishing\Actions;

use App\Enums\OccurrenceStatus;
use App\Enums\ReviewStatus;
use App\Ingestion\Data\NormalisedFacts;
use App\Ingestion\Pipeline\ContentHasher;
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
 * occurrence horizon exist to be cancelled, so every listed date is also
 * stored in events.cancelled_dates and OccurrenceGenerator inserts a later
 * one as Cancelled when the horizon reaches it. Lock order: draft → event
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
        private ContentHasher $hasher,
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
                $this->acceptBaseline($locked);
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

        $keys = array_values(array_unique(array_filter($keys, is_string(...))));
        sort($keys, SORT_STRING);

        // Kept on the event so a date beyond the occurrence horizon is
        // inserted as Cancelled when the nightly sync reaches it.
        $event->forceFill(['cancelled_dates' => $keys])->save();

        $occurrences = EventOccurrence::query()
            ->where('event_id', $event->id)
            ->whereIn('recurrence_key', $keys)
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
     *
     * Review CR-02: the baseline is the feed facts this draft was built from
     * (raw_payload, written by ingestion with the item's payload), not
     * whatever the item holds now. When they differ (the feed moved after
     * the draft was written and the draft was not replaced) the baseline is
     * the draft's facts, so the next run sees content_hash !== accepted_hash
     * and raises the difference as an update instead of going quiet.
     */
    private function acceptBaseline(EventDraft $draft): void
    {
        $item = SourceItem::query()->whereKey($draft->source_item_id)->lockForUpdate()->firstOrFail();

        $hash = $item->content_hash;
        $payload = $item->payload;
        $draftFacts = self::draftFacts($draft);

        if ($draftFacts !== null) {
            $draftHash = $this->hasher->contentHashFromPayload($draftFacts);

            if ($hash === null || ! hash_equals($draftHash, $hash)) {
                $hash = $draftHash;
                $payload = $draftFacts;
            }
        }

        $item->forceFill([
            'accepted_hash' => $hash,
            'accepted_payload' => $payload,
            'review_outcome' => 'approved',
        ])->save();
    }

    /**
     * The feed facts a discovered draft was built from, normalised through
     * NormalisedFacts so they hash exactly like source_items.payload; null
     * when the draft carries none (then the item's own content is used).
     *
     * @return array<string, mixed>|null
     */
    private static function draftFacts(EventDraft $draft): ?array
    {
        $raw = $draft->raw_payload;

        if (! is_array($raw) || ! is_string($raw['start_date'] ?? null) || $raw['start_date'] === '') {
            return null;
        }

        return NormalisedFacts::fromPayload($raw)->toPayload();
    }
}
