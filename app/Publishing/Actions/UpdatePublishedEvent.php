<?php

namespace App\Publishing\Actions;

use App\Calendar\OccurrenceGenerator;
use App\Models\Event;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Data\PublishableEventData;
use App\Publishing\EventSlugger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY updater of an event's content and schedule (ADM-03 edit).
 *
 * Locks the events row (SELECT ... FOR UPDATE) so an edit and the daily
 * calendar:sync-occurrences run never interleave, writes only the allowlisted
 * attributes from PublishableEventData::toAttributes() (never status, origin,
 * approved_by or approved_at), and re-syncs occurrences in the same
 * transaction. Occurrence status is preserved by OccurrenceGenerator unless
 * the rule no longer yields that date.
 *
 * Refuses (InvalidArgumentException, nothing written) to switch an event
 * between one-off and repeating (WR-06): OccurrenceUid gives a one-off
 * "{id}@domain" and a series instance "{id}-{key}@domain", and promises a
 * UID never changes. Switching between repeating patterns is allowed. The
 * admin's workaround is to hide the event and create a new one.
 *
 * Also refuses any edit to an event whose stored recurrence_rule does not
 * round-trip exactly through ManualRecurrence for the event's own start
 * date and all_day (WR-09, tightened by WR-10; see
 * PublishableEventData::editableRecurrence()). Otherwise the next save would
 * rewrite the days to the start date's weekday and sync would delete the
 * old dates, including admin cancellations and the UIDs residents imported.
 * So no caller (a forged Livewire save, a future job) can overwrite a rule
 * the form only shows as "Does not repeat". Hide and cancel still work
 * through ChangeEventStatus.
 *
 * Status changes (hide/cancel/reinstate) are separate actions (plan 01-05).
 */
final class UpdatePublishedEvent
{
    public const UNSUPPORTED_RULE_REFUSED = "This event's repeat rule can't be edited here. You can still hide or cancel it.";

    private const TYPE_SWITCH_REFUSED = "An event can't switch between one-off and repeating. Hide it and create a new event instead.";

    public function __construct(
        private OccurrenceGenerator $occurrences,
        private EventSlugger $slugger,
    ) {}

    /**
     * @throws AuthorizationException when the editor is not the configured admin
     * @throws InvalidArgumentException when the stored rule is unsupported or
     *                                  the edit would switch one-off <-> repeating
     */
    public function __invoke(Event $event, PublishableEventData $data, User $editor): Event
    {
        // Defence in depth beside the panel gate (T-01-20).
        AdminGate::authorize($editor, 'Only the admin can edit events.');

        return DB::transaction(function () use ($event, $data): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Both checks use the locked row and run before any write.
            if (! self::canEdit($locked)) {
                throw new InvalidArgumentException(self::UNSUPPORTED_RULE_REFUSED);
            }

            // WR-06: one-off and series UIDs differ, so the type never changes.
            if (($locked->recurrence_rule === null) === $data->isRecurring()) {
                throw new InvalidArgumentException(self::TYPE_SWITCH_REFUSED);
            }

            $locked->fill($data->toAttributes());
            // Replaces the DTO's recompute-from-title slug with a unique one (EVT-02).
            $this->slugger->assign($locked);
            $locked->save();

            $this->occurrences->sync($locked);

            return $locked->refresh();
        });
    }

    /**
     * Whether the edit form can represent the event's stored recurrence rule
     * exactly, i.e. it round-trips for the event's start date (WR-09, WR-10).
     * One-off events always can.
     */
    public static function canEdit(Event $event): bool
    {
        return $event->recurrence_rule === null
            || PublishableEventData::editableRecurrence($event) !== null;
    }
}
