<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Review queue tuning (Phase 2)
    |--------------------------------------------------------------------------
    |
    | Plain integers (and one ratio). These are code, not secrets, so there
    | are no env lookups. Later plans read these keys and never edit the file.
    |
    */

    'quality' => [

        // Points taken off a perfect 100 for each problem found (02-08).
        'weights' => [
            'missing_field' => 50,
            'parse_error' => 40,
            'venue_unrecognised' => 20,
            'category_other' => 15,
            'no_end_time' => 10,
            'repeats_unset' => 25,
        ],

        // Lowest score in each band; anything below medium is Low.
        'bands' => [
            'high' => 80,
            'medium' => 50,
        ],

    ],

    // Days a rejected draft is kept before pruning: spam goes sooner.
    'retention_days' => [
        'spam' => 30,
        'default' => 90,
    ],

    // Public submission form guards (02-05).
    'submission' => [
        // A human takes at least this long to fill the form.
        'min_seconds' => 3,
        // A form older than this is stale and must be reloaded.
        'max_age_minutes' => 120,
        // Accepted submissions per visitor.
        'per_hour' => 5,
        'per_day' => 10,
    ],

    // pg_trgm similarity at or above which a free-text venue is suggested.
    'venue_similarity_threshold' => 0.3,

];
