<?php

use App\Http\Controllers\Admin\EventStreamController;
use App\Http\Controllers\Admin\FunnelController;
use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\PlayerController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard JSON API. Lives in the web group so the Vue SPA authenticates with the
| session cookie and CSRF token rather than a long-lived token in the browser.
*/
Route::prefix('api/admin')->name('admin.')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('auth')->group(function () {
        Route::get('games', [GameController::class, 'index'])->name('games.index');
        Route::post('games', [GameController::class, 'store'])->middleware('demo.read-only')->name('games.store');
        Route::post('games/{game}/rotate-key', [GameController::class, 'rotateKey'])->middleware('demo.read-only')->name('games.rotate-key');

        Route::prefix('games/{game}')->scopeBindings()->group(function () {
            Route::get('overview', [ReportController::class, 'overview'])->name('reports.overview');
            Route::get('events/report', [ReportController::class, 'events'])->name('reports.events');
            Route::get('events/catalog', [ReportController::class, 'eventCatalog'])->name('events.catalog');
            Route::get('events', EventStreamController::class)->name('events.index');
            Route::get('retention', [ReportController::class, 'retention'])->name('reports.retention');

            Route::get('funnels/preview', [FunnelController::class, 'preview'])->name('funnels.preview');
            Route::get('funnels/{funnel}/report', [FunnelController::class, 'report'])->name('funnels.report');
            Route::get('funnels', [FunnelController::class, 'index'])->name('funnels.index');
            Route::apiResource('funnels', FunnelController::class)->only(['store', 'update', 'destroy'])->middleware('demo.read-only');

            Route::get('players', [PlayerController::class, 'index'])->name('players.index');
            Route::get('players/{player}', [PlayerController::class, 'show'])->name('players.show');
            Route::get('players/{player}/events', [PlayerController::class, 'timeline'])->name('players.timeline');
        });
    });
});

// Every other path belongs to the Vue router.
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$).*')->name('app');
