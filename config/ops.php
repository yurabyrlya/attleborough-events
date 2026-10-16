<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operations health thresholds (OPS-04)
    |--------------------------------------------------------------------------
    |
    | Plain integers in seconds/hours. These are code, not secrets, so there
    | are no env lookups.
    |
    */

    // schedule:run beats every minute; three missed minutes means Stale.
    'scheduler_stale_after' => 180,

    // QueueHeartbeat is dispatched every five minutes; two missed means Stale.
    'queue_stale_after' => 600,

    // The daily 03:10 sync; 26 hours allows a late run without a false alarm.
    'calendar_sync_stale_after' => 93600,

    // queue:prune-failed --hours=720 (30 days) runs daily in routes/console.php.
    'failed_jobs_retention_hours' => 720,

    // Upper bound for ops:drill --fail-for.
    'drill_max_fail_seconds' => 3600,

];
