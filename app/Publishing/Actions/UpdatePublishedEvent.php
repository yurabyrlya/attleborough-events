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
 * Status changes (hide/cancel/reinstate) are separate actions (plan 01-05).
 */
final class UpdatePublishedEvent
{
    public function __construct(
        private OccurrenceGenerator $occurrences,
        private EventSlugger $slugger,
    ) {}

    private const TYPE_SWITCH_REFUSED = "An event can't switch between one-off and repeating. Hide it and create a new event instead.";

    /**
     * @throws AuthorizationException when the editor is not the configured admin
     * @throws InvalidArgumentException when the edit would switch one-off <-> repeating
     */
    public function __invoke(Event $event, PublishableEventData $data, User $editor): Event
    {
        // Defence in depth beside the panel gate (T-01-20).
        AdminGate::authorize($editor, 'Only the admin can edit events.');

        return DB::transaction(function () use ($event, $data): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            // Compared on the locked row, before any write (WR-06).
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
}
