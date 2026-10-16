<?php

use App\Jobs\QueueHeartbeat;
use App\Models\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Roll the recurring-series horizon forward daily. 03:10 London avoids the
// 01:00-02:00 DST transition hour (REC-01).
Schedule::command('calendar:sync-occurrences')
    ->dailyAt('03:10')
    ->timezone('Europe/London')
    ->withoutOverlapping();

// Scheduler liveness (OPS-04): every schedule:run proves cron is alive by
// upserting the 'scheduler' heartbeat row (a table, never the cache).
Schedule::call(fn () => Heartbeat::beat(Heartbeat::SCHEDULER))
    ->everyMinute()
    ->name('heartbeat:scheduler');

// Worker liveness (OPS-04): only a worker processing this job writes the
// 'queue' heartbeat. ShouldBeUnique keeps at most one waiting copy. The class
// name (not an instance) is passed so queued_at is taken at dispatch time.
Schedule::job(QueueHeartbeat::class, 'default', 'database')
    ->everyFiveMinutes();

// Keep failed_jobs to 30 days (config ops.failed_jobs_retention_hours = 720).
Schedule::command('queue:prune-failed --hours=720')
    ->daily();
