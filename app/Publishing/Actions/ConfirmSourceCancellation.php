<?php

namespace App\Publishing\Actions;

use App\Enums\DraftKind;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\ReviewStatus;
use App\Models\Event;
use App\Models\EventDraft;
use App\Models\EventOccurrence;
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
 * Confirms a source cancellation draft (REV-07, D-08).
 *
 * - Date cancellation (diff {cancelled_keys: [Y-m-d, ...]}): exactly those
 *   dates of the series are cancelled through ChangeOccurrenceStatus;
 *   every other date is untouched. The dates are also added to
 *   events.cancelled_dates, so a date beyond the occurrence horizon (no row
 *   yet) is inserted as Cancelled when the nightly sync reaches it.
 * - Whole-event cancellation (diff {cancelled} from STATUS:CANCELLED, or
 *   {status: active → missing} from MissingItemDetector): the event is
 *   cancelled through ChangeEventStatus, so the event page says
 *   "Cancelled" (A8).
 *
 * Cancelled dates and events stay on the public pages with a "Cancelled"
 * text label; nothing is deleted. A date or event the admin already
 * cancelled stays cancelled and the confirm succeeds. A date or event the
 * admin hid stays hidden (the admin's choice wins; hidden → cancelled is
 * not a transition the state machines allow), though the date is still
 * recorded in cancelled_dates.
 *
 * Admin-only, checked before the transaction. In ONE transaction: draft
 * FOR UPDATE (transition and kind checked on it) → event FOR UPDATE →
 * occurrences (ChangeOccurrenceStatus, which re-locks event then
 * occurrence) → source item last, whose cancelled part of the baseline
 * moves (SourceBaseline::moveCancelledPart). All logs, including the
 * nested status actions', run after commit (C9).
 */
final class ConfirmSourceCancellation
{
    public const string WRONG_KIND = 'Only source cancellation drafts can be confirmed.';

    public function __construct(
        private ChangeOccurrenceStatus $occurrenceStatus,
        private ChangeEventStatus $eventStatus,
        private SourceBaseline $baseline,
    ) {}

    /**
     * @throws AuthorizationException when the reviewer is not the configured admin
     * @throws InvalidReviewTransition when the draft is no longer pending review
     * @throws DraftNotPublishable when the draft is not a cancellation of a published event
     */
    public function __invoke(EventDraft $draft, User $reviewer): EventDraft
    {
        // Defence in depth beside the panel gate (T-03-28).
        AdminGate::authorize($reviewer, 'Only the admin can confirm source cancellations.');

        $confirmed = DB::transaction(function () use ($draft, $reviewer): EventDraft {
            $locked = EventDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            $locked->review_status->assertCanTransitionTo(ReviewStatus::Approved);

            if ($locked->kind !== DraftKind::Cancellation) {
                throw new DraftNotPublishable([self::WRONG_KIND]);
            }

            if ($locked->event_id === null) {
                throw new DraftNotPublishable(['This cancellation has no published event.']);
            }

            $event = Event::query()->whereKey($locked->event_id)->lockForUpdate()->firstOrFail();
            $keys = self::cancelledKeys($locked->diff ?? []);

            if ($keys !== []) {
                $this->cancelDates($event, $keys, $reviewer);
            } else {
                $this->cancelEvent($event, $reviewer);
            }

            $locked->forceFill([
                'review_status' => ReviewStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
            ])->save();

            if ($locked->source_item_id !== null) {
                $item = SourceItem::query()->whereKey($locked->source_item_id)->lockForUpdate()->first();

                if ($item !== null) {
                    $this->baseline->moveCancelledPart($item);
                }
            }

            return $locked;
        });

        // Ids only: never titles or contact details. After the outermost
        // commit, so a rolled-back confirm is never logged.
        DB::afterCommit(fn () => Log::info('source cancellation confirmed', [
            'draft_id' => $confirmed->id,
            'event_id' => $confirmed->event_id,
            'user_id' => $reviewer->id,
        ]));

        return $confirmed->refresh();
    }

    /**
     * The listed dates of a date cancellation, unique and ascending; empty
     * for a whole-event cancellation.
     *
     * @param  array<string, mixed>  $diff
     * @return list<string>
     */
    private static function cancelledKeys(array $diff): array
    {
        $keys = $diff['cancelled_keys'] ?? [];

        if (! is_array($keys)) {
            return [];
        }

        $keys = array_values(array_unique(array_filter(
            $keys,
            fn (mixed $key): bool => is_string($key) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) === 1,
        )));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * D-08: only the listed dates change; they are remembered on the event
     * so the sync inserts a later one as Cancelled.
     *
     * @param  list<string>  $keys
     */
    private function cancelDates(Event $event, array $keys, User $reviewer): void
    {
        $dates = array_values(array_unique([...$event->cancelled_dates, ...$keys]));
        sort($dates, SORT_STRING);

        if ($dates !== $event->cancelled_dates) {
            $event->forceFill(['cancelled_dates' => $dates])->save();
        }

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
     * A8: a whole-event cancellation goes through the event's own status,
     * so the event page heading says "Cancelled".
     */
    private function cancelEvent(Event $event, User $reviewer): void
    {
        if ($event->status === EventStatus::Published) {
            ($this->eventStatus)($event, EventStatus::Cancelled, $reviewer);
        }
    }
}
