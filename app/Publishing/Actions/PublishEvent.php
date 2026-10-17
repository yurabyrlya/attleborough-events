<?php

namespace App\Publishing\Actions;

use App\Calendar\OccurrenceGenerator;
use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Data\PublishableEventData;
use App\Publishing\EventSlugger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY creator of Event rows (ADM-02 single write choke point).
 *
 * The approver must be the admin (App\Publishing\AdminGate, WR-01): the check
 * runs before the transaction opens, so a refused call writes nothing. Phase 2's
 * approval path calls this action outside the Filament panel gate.
 *
 * Persists the event as published with the approving admin recorded, and
 * materialises its occurrences in the same transaction: if occurrence
 * generation fails, nothing is persisted.
 *
 * No implicit dedupe: identical data published twice creates two events
 * (duplicate detection arrives in Phase 3).
 *
 * Publishing state (status, origin, approved_by, approved_at) is not
 * mass-assignable on Event; it is written here with forceFill (WR-07).
 */
final class PublishEvent
{
    public function __construct(
        private OccurrenceGenerator $occurrences,
        private EventSlugger $slugger,
    ) {}

    /**
     * @throws AuthorizationException when the approver is not the configured admin
     */
    public function __invoke(PublishableEventData $data, User $approver, EventOrigin $origin): Event
    {
        AdminGate::authorize($approver, 'Only the admin can publish events.');

        return DB::transaction(function () use ($data, $approver, $origin): Event {
            $event = new Event($data->toAttributes());
            $event->forceFill([
                'status' => EventStatus::Published,
                'origin' => $origin,
                'approved_by' => $approver->id,
                'approved_at' => now()->utc(),
            ]);

            // The slugger's unique slug overrides the DTO's candidate (EVT-02).
            $this->slugger->assign($event);
            $event->save();

            $this->occurrences->sync($event);

            return $event;
        });
    }
}
