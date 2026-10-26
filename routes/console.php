<?php

use Illuminate\Support\Facades\Schedule;

// Hosted demo only: stream newly simulated events, rebuilding the history after midnight UTC.
Schedule::command('analytics:demo-refresh', ['--max-seconds' => config('analytics.demo_build_seconds')])
    ->everyFiveMinutes()
    ->when(fn () => (bool) config('analytics.demo_mode'))
    ->withoutOverlapping(30)
    ->onOneServer();
