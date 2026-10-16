<?php

namespace App\Calendar;

use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Materialises an event's occurrences.
 *
 * Upserts on (event_id, recurrence_key) and updates ONLY the instants, never
 * `status`, so admin-set cancellations/hides survive re-syncs. Future rows the
 * schedule no longer produces are deleted; past rows are never touched.
 */
final class OccurrenceGenerator
{
    public function __construct(private EventTiming $timing) {}

    public function sync(Event $event, ?CarbonImmutable $now = null): void
    {
        $tz = $event->timezone ?: (string) config('calendar.timezone', 'Europe/London');
        $windowStart = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();

        $rows = array_map(fn (OccurrenceTiming $t): array => [
            'event_id' => $event->id,
            'recurrence_key' => $t->key,
            // upsert bypasses casts: pass explicit UTC strings (session tz is UTC).
            'starts_at' => $t->startsAt->utc()->format('Y-m-d H:i:s'),
            'ends_at' => $t->endsAt?->utc()->format('Y-m-d H:i:s'),
            'expires_at' => $t->expiresAt->utc()->format('Y-m-d H:i:s'),
            'status' => OccurrenceStatus::Scheduled->value, // used on INSERT only
        ], $this->instances($event));

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
     * @return list<OccurrenceTiming>
     */
    private function instances(Event $event): array
    {
        if ($event->recurrence_rule !== null) {
            throw new LogicException('Recurring events are not supported yet.');
        }

        return [$this->timing->forDate($event, $event->start_date->format('Y-m-d'))];
    }
}
