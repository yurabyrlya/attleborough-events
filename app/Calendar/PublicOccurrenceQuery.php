<?php

namespace App\Calendar;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;

/**
 * Read model for the public list (CAL-01..05).
 *
 * Base set: event published or cancelled (cancelled shows a badge),
 * occurrence scheduled or cancelled, and not yet expired (expires_at > now,
 * strictly). Ordered by starts_at then id for a stable order.
 *
 * With a date filter, an occurrence is in the window when
 * starts_at < window end AND expires_at > max(now, window start), so a
 * multi-day occurrence appears in every window it overlaps. Every Carbon
 * bound to the query is converted to UTC first: the query builder formats
 * Carbon values without converting zones.
 */
final class PublicOccurrenceQuery
{
    /**
     * @return Paginator<int, EventOccurrence>
     */
    public function paginate(CalendarFilters $filters, CarbonImmutable $now): Paginator
    {
        $now = $now->utc();
        $window = $filters->when !== null ? DateWindow::for($filters->when, $now) : null;
        $notExpiredBefore = $window !== null && $window->startUtc()->greaterThan($now)
            ? $window->startUtc()
            : $now;

        return EventOccurrence::query()
            ->select('event_occurrences.*')
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->whereIn('events.status', [EventStatus::Published->value, EventStatus::Cancelled->value])
            ->whereIn('event_occurrences.status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::Cancelled->value])
            ->where('event_occurrences.expires_at', '>', $notExpiredBefore->utc())
            ->when($window, fn ($query, DateWindow $window) => $query->where('event_occurrences.starts_at', '<', $window->endUtc()))
            ->orderBy('event_occurrences.starts_at')
            ->orderBy('event_occurrences.id')
            ->with(['event.venue', 'event.category'])
            ->simplePaginate((int) config('calendar.page_size', 30))
            ->appends($filters->toQuery());
    }
}
