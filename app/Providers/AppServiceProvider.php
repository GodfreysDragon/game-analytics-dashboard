<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ingest', fn (Request $request) => Limit::perMinute(config('analytics.ingest_requests_per_minute'))
            ->by('game:'.($request->attributes->get('game')?->id ?? $request->ip())));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        // `artisan serve` strips TEMP and TMP from the server process. On Windows SQLite then has
        // nowhere to spill large sorts, and the events and funnel reports fail with "unable to open database file".
        ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP']));
    }
}
