<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class DemoRefreshController extends Controller
{
    /**
     * Run analytics:demo-refresh for an external scheduler. Serverless hosts such as Cloud Run
     * keep no process alive for `schedule:run`, so Cloud Scheduler calls this instead.
     * Anything but the configured bearer token gets a 404, so the endpoint stays hidden.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('analytics.demo_refresh_token');

        if (! config('analytics.demo_mode') || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $lock = Cache::lock('analytics:demo-refresh', 1800);

        if (! $lock->get()) {
            return response()->json(['message' => 'A refresh is already running.'], Response::HTTP_CONFLICT);
        }

        try {
            // A rebuild after midnight UTC takes minutes against a remote database, a few at a time.
            set_time_limit(0);
            $exitCode = Artisan::call('analytics:demo-refresh', ['--max-seconds' => config('analytics.demo_build_seconds')]);

            return response()->json(
                ['output' => trim(Artisan::output())],
                $exitCode === 0 ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        } finally {
            $lock->release();
        }
    }
}
