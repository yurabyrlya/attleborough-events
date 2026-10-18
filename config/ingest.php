<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Source ingestion (Phase 3)
    |--------------------------------------------------------------------------
    |
    | The polite fetcher (App\Ingestion\Http\HttpFetcher) and the ingestion
    | pipeline read these keys. Plain numbers are code, not secrets, so the
    | only env lookup is the crawler contact URL. The import window is
    | calendar.horizon_days (D-20); there is deliberately no window key here.
    |
    */

    // RFC 9309 product token: letters only. Sent as
    // "AttleboroughEventsBot/1.0 (+{contact_url})" on every request.
    'user_agent_product' => 'AttleboroughEventsBot/1.0',

    // Public page that explains the crawler. A placeholder until the Phase 5
    // about page exists; production sets CRAWLER_CONTACT_URL. An empty value
    // (as in .env.example) also falls back to the placeholder.
    'contact_url' => env('CRAWLER_CONTACT_URL') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/about-crawler',

    // Seconds. Per request; each request also stays inside fetch_deadline.
    'connect_timeout' => 10,
    'timeout' => 30,

    // Overall budget for one fetch (every hop and inline host wait), below
    // FetchSourceJob's 75-second job timeout.
    'fetch_deadline' => 55,

    // Largest decoded body accepted, in bytes (SRC-06): 5 MiB.
    'max_bytes' => 5 * 1024 * 1024,

    // Redirects followed by hand; every hop is re-checked (SSRF, robots).
    'max_redirects' => 5,

    // Seconds between requests to one host (D-02); robots Crawl-delay can
    // only make it longer.
    'min_host_delay' => 5,

    // Longest host wait a worker sleeps through; longer waits release the job.
    'max_inline_wait' => 20,

    // Items read from one feed before the rest are skipped.
    'max_events' => 5000,

    // Consecutive failed live runs before a source turns degraded / unhealthy.
    'health' => [
        'degraded_after' => 3,
        'unhealthy_after' => 5,
    ],

    // Possible-duplicate matching: pg_trgm similarity and start-time window.
    'duplicate' => [
        'title_threshold' => 0.3,
        'venue_threshold' => 0.3,
        'start_tolerance_minutes' => 60,
    ],

    // Days a fetched raw body is kept for inspection.
    'raw_retention_days' => 30,

    'schedule' => [
        // Deterministic spread added to each source's check time.
        'jitter_minutes' => 20,
    ],

    // Minutes a preview run's body may be reused by "Create drafts" (D-24).
    'preview_reuse_minutes' => 60,

];
