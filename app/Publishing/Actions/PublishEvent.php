<?php

namespace App\Publishing\Actions;

use App\Calendar\OccurrenceGenerator;
use App\Enums\EventOrigin;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Publishing\Data\PublishableEventData;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY creator of Event rows (ADM-02 single write choke point).
 *
 * Persists the event as published with the approving admin recorded, and
 * materialises its occurrences in the same transaction: if occurrence
 * generation fails, nothing is persisted.
 *
 * No implicit dedupe: identical data published twice creates two events
 * (duplicate detection arrives in Phase 3).
 */
final class PublishEvent
{
    public function __construct(private OccurrenceGenerator $occurrences) {}

    public function __invoke(PublishableEventData $data, User $approver, EventOrigin $origin): Event
    {
        return DB::transaction(function () use ($data, $approver, $origin): Event {
            $event = Event::create($data->toAttributes() + [
                'status' => EventStatus::Published,
                'origin' => $origin,
                'approved_by' => $approver->id,
                'approved_at' => now()->utc(),
            ]);

            $this->occurrences->sync($event);

            return $event;
        });
    }
}
