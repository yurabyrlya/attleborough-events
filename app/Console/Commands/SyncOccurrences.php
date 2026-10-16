<?php

namespace App\Console\Commands;

use App\Calendar\OccurrenceGenerator;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rolls the 90-day horizon forward for every recurring series (REC-01).
 *
 * Includes ALL statuses (hidden/cancelled too) so unhiding or reinstating a
 * series is instant. Each event syncs in its own transaction holding a row
 * lock on the events row, so it never interleaves with an admin edit. One
 * failing event is reported and skipped; the exit code is 1 when any failed.
 */
class SyncOccurrences extends Command
{
    protected $signature = 'calendar:sync-occurrences {--event=* : Only these event ids}';

    protected $description = 'Materialise occurrences of recurring events over the rolling horizon';

    public function handle(OccurrenceGenerator $occurrences): int
    {
        $only = array_values(array_filter(array_map('intval', (array) $this->option('event'))));
        $synced = 0;
        $failed = [];

        $events = Event::query()
            ->whereNotNull('recurrence_rule')
            ->when($only !== [], fn ($query) => $query->whereKey($only))
            ->lazyById(100);

        foreach ($events as $event) {
            try {
                DB::transaction(function () use ($event, $occurrences): void {
                    $locked = Event::query()->whereKey($event->id)->lockForUpdate()->first();

                    if ($locked !== null && $locked->recurrence_rule !== null) {
                        $occurrences->sync($locked);
                    }
                });

                $synced++;
            } catch (Throwable $e) {
                report($e);
                // Id and message only: never event content or organiser details (T-01-22).
                Log::error('calendar:sync-occurrences failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
                $failed[] = $event->id;
            }
        }

        $this->info("Synced {$synced} series, ".count($failed).' failed.');

        if ($failed !== []) {
            $this->error('Failed event ids: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
