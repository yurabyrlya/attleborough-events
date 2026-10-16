<?php

namespace App\Calendar;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * Read model for the public list (CAL-01).
 *
 * Visible = event published or cancelled (cancelled shows a badge),
 * occurrence scheduled or cancelled, and not yet expired (expires_at > now,
 * strictly). Ordered by starts_at then id for a stable order.
 */
final class PublicOccurrenceQuery
{
    /**
     * @return Paginator<int, EventOccurrence>
     */
    public function paginate(?CarbonImmutable $now = null): Paginator
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return EventOccurrence::query()
            ->select('event_occurrences.*')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->whereIn('events.status', [EventStatus::Published->value, EventStatus::Cancelled->value])
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::Cancelled->value])
            ->where('event_occurrences.expires_at', '>', $now->format('Y-m-d H:i:s'))
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id')
            ->with(['event.venue', 'event.category'])
            ->simplePaginate((int) config('calendar.page_size', 30))
            ->withQueryString();
    }
}
