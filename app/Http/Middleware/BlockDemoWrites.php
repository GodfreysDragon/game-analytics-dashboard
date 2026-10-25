<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In demo mode the dashboard is read-only: visitors share one account, so nothing they do
 * may change what the next visitor sees.
 */
class BlockDemoWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('analytics.demo_mode')) {
            return response()->json(['message' => 'This is a read-only demo, so changes are turned off.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
