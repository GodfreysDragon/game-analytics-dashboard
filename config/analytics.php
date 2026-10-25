<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingestion window
    |--------------------------------------------------------------------------
    |
    | Events older than this many days are rejected so a misconfigured client
    | cannot rewrite old cohorts, and timestamps more than the allowed clock
    | skew in the future are rejected as bad device clocks.
    |
    */

    'max_event_age_days' => (int) env('ANALYTICS_MAX_EVENT_AGE_DAYS', 30),

    'max_clock_skew_minutes' => (int) env('ANALYTICS_MAX_CLOCK_SKEW_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Ingestion rate limit
    |--------------------------------------------------------------------------
    |
    | Requests per minute per game (each request carries up to 500 events).
    |
    */

    'ingest_requests_per_minute' => (int) env('ANALYTICS_INGEST_RPM', 600),

    'platforms' => ['ios', 'android', 'pc', 'web', 'console'],

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | Days of history the database seeder simulates. Fewer days seed faster,
    | e.g. DEMO_DAYS=14 php artisan migrate:fresh --seed for a quick look.
    |
    */

    'demo_days' => (int) env('DEMO_DAYS', 60),

];
