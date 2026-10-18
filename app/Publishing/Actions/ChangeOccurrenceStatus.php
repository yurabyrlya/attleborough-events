<?php

namespace App\Publishing\Actions;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Publishing\AdminGate;
use App\Publishing\Exceptions\InvalidStatusTransition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY writer of event_occurrences.status after insert (ADM-03).
 *
 * Admin-only. Locks the parent events row FIRST, then the occurrence row, in
 * the same order as UpdatePublishedEvent and calendar:sync-occurrences, so a
 * status write never interleaves with a prune and the lock order never
 * deadlocks. The transition is asserted on the freshly locked status, never on
 * the caller's (possibly stale) model. Writes status + audit columns only;
 * never touches times, recurrence_key, and never removes the row.
 */
final class ChangeOccurrenceStatus
{
    /**
     * @throws AuthorizationException when the actor is not the configured admin
     * @throws InvalidStatusTransition when the enum machine refuses the move
     * @throws ModelNotFoundException when the row was pruned meanwhile
     */
    public function __invoke(EventOccurrence $occurrence, OccurrenceStatus $to, User $actor): EventOccurrence
    {
        // Defence in depth beside the panel gate (T-01-30).
        AdminGate::authorize($actor, 'Only the admin can change occurrence status.');

        [$changed, $from] = DB::transaction(function () use ($occurrence, $to, $actor): array {
            Event::query()->whereKey($occurrence->event_id)->lockForUpdate()->firstOrFail();

            $locked = EventOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $from->assertCanTransitionTo($to);

            $locked->forceFill([
                'status' => $to,
                'status_changed_at' => CarbonImmutable::now(),
                'status_changed_by' => $actor->id,
            ])->save();

            return [$locked, $from];
        });

        // Ids only: never titles or contact details. After the outermost
        // commit, so a status change rolled back with a caller's
        // transaction (ConfirmSourceCancellation, C9) is never logged.
        // Outside a transaction it runs at once.
        DB::afterCommit(fn () => Log::info('occurrence status changed', [
            'occurrence_id' => $changed->id,
            'event_id' => $changed->event_id,
            'from' => $from->value,
            'to' => $to->value,
            'user_id' => $actor->id,
        ]));

        return $changed->refresh();
    }
}
