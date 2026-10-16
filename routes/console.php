<?php

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
