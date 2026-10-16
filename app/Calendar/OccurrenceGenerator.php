<?php

namespace App\Calendar;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materialises an event's occurrences.
 *
 * Recurring series are materialised over a rolling horizon:
 * [today 00:00, today + calendar.horizon_days 00:00) Europe/London, with an
 * exclusive end, expanded only by RecurrenceExpander (sabre/vobject).
 *
 * Upserts on (event_id, recurrence_key) and updates ONLY the instants, never
 * `status`, so admin-set cancellations/hides survive re-syncs. Future rows the
 * schedule no longer produces are deleted; past rows are never touched.
 */
final class OccurrenceGenerator
{
    public function __construct(
        private EventTiming $timing,
        private RecurrenceExpander $expander,
    ) {}

    public function sync(Event $event, ?CarbonImmutable $now = null): void
    {
        $tz = $event->timezone ?: (string) config('calendar.timezone', 'Europe/London');
        $windowStart = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
        $windowEnd = $windowStart->addDays((int) config('calendar.horizon_days', 90));

        $rows = array_map(fn (OccurrenceTiming $t): array => [
            'event_id' => $event->id,
            'recurrence_key' => $t->key,
            // upsert bypasses casts: pass explicit UTC strings (session tz is UTC).
            'starts_at' => $t->startsAt->utc()->format('Y-m-d H:i:s'),
            'ends_at' => $t->endsAt?->utc()->format('Y-m-d H:i:s'),
            'expires_at' => $t->expiresAt->utc()->format('Y-m-d H:i:s'),
            'status' => OccurrenceStatus::Scheduled->value, // used on INSERT only
        ], $this->instances($event, $windowStart, $windowEnd));

        DB::transaction(function () use ($event, $rows, $windowStart): void {
            EventOccurrence::upsert(
                $rows,
                uniqueBy: ['event_id', 'recurrence_key'],
                update: ['starts_at', 'ends_at', 'expires_at'],
            );

            $event->occurrences()
                ->where('starts_at', '>=', $windowStart->utc()->format('Y-m-d H:i:s'))
                ->whereNotIn('recurrence_key', array_column($rows, 'recurrence_key'))
                ->delete();
        });
    }

    /**
     * One-off: exactly one instance from start_date, regardless of horizon.
     * Recurring: the instances sabre yields in [windowStart, windowEnd), in
     * chronological order so that newly inserted ids ascend with date.
     *
     * @return list<OccurrenceTiming>
     */
    private function instances(Event $event, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        if ($event->recurrence_rule === null) {
            return [$this->timing->forDate($event, $event->start_date->format('Y-m-d'))];
        }

        return array_map(
            fn (string $key): OccurrenceTiming => $this->timing->forDate($event, $key),
            $this->expander->instanceDates($event, $windowStart, $windowEnd),
        );
    }
}
