<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar rules
    |--------------------------------------------------------------------------
    |
    | All instants are stored in UTC. Wall-clock schedules, date windows and
    | display are computed in this timezone.
    |
    */

    'timezone' => 'Europe/London',

    // Rolling horizon (days) over which recurring series are materialised.
    'horizon_days' => 90,

    // An occurrence with no end time expires this many hours after its start.
    'no_end_expiry_hours' => 3,

    // Occurrences per page on the public list.
    'page_size' => 30,

];
