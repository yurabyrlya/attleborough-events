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
 * Status changes (hide/cancel/reinstate) are separate actions (plan 01-05).
 */
final class UpdatePublishedEvent
{
    public function __construct(
        private OccurrenceGenerator $occurrences,
        private EventSlugger $slugger,
    ) {}

    /**
     * @throws AuthorizationException when the editor is not the configured admin
     */
    public function __invoke(Event $event, PublishableEventData $data, User $editor): Event
    {
        // Defence in depth beside the panel gate (T-01-20).
        AdminGate::authorize($editor, 'Only the admin can edit events.');

        return DB::transaction(function () use ($event, $data): Event {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $locked->fill($data->toAttributes());
            // Replaces the DTO's recompute-from-title slug with a unique one (EVT-02).
            $this->slugger->assign($locked);
            $locked->save();

            $this->occurrences->sync($locked);

            return $locked->refresh();
        });
    }
}
