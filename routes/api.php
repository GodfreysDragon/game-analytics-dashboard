<?php

use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Controllers\DemoRefreshController;
use Illuminate\Support\Facades\Route;

/*
| Public ingestion endpoint called by game clients and servers. Stateless: the game is
| identified by its ingest key, and the rate limit is applied per game.
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('events', IngestController::class)
        ->middleware(['ingest.key', 'throttle:ingest'])
        ->name('events.store');
});

// Hosted demo: called by an external scheduler (e.g. Cloud Scheduler) with DEMO_REFRESH_TOKEN.
Route::post('internal/demo-refresh', DemoRefreshController::class)->name('api.demo-refresh');
