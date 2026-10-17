<?php

namespace App\Publishing\Actions;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Exceptions\InvalidStatusTransition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY writer of events.status after publish (ADM-03).
 *
 * Admin-only. Re-reads the events row with SELECT ... FOR UPDATE (same lock
 * as UpdatePublishedEvent and calendar:sync-occurrences) and asserts the
 * transition on that fresh status. Writes status + audit columns only.
 *
 * Deliberately does NOT touch occurrences or call the generator: occurrence
 * status is per row and the public read combines the two (hidden wins, then
 * cancelled shows one badge), so showing or reinstating the event restores
 * exactly what the admin had set per date.
 */
final class ChangeEventStatus
{
    /**
     * @throws AuthorizationException when the actor is not the configured admin
     * @throws InvalidStatusTransition when the enum machine refuses the move
     */
    public function __invoke(Event $event, EventStatus $to, User $actor): Event
    {
        // Defence in depth beside the panel gate (T-01-30).
        AdminGate::authorize($actor, 'Only the admin can change event status.');

        [$changed, $from] = DB::transaction(function () use ($event, $to, $actor): array {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $from->assertCanTransitionTo($to);

            $locked->forceFill([
                'status' => $to,
                'status_changed_at' => CarbonImmutable::now(),
                'status_changed_by' => $actor->id,
            ])->save();

            return [$locked, $from];
        });

        // Ids only: never titles or contact details.
        Log::info('event status changed', [
            'event_id' => $changed->id,
            'from' => $from->value,
            'to' => $to->value,
            'user_id' => $actor->id,
        ]);

        return $changed->refresh();
    }
}
