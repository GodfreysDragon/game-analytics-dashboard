<?php

use App\Http\Middleware\AuthenticateIngestKey;
use App\Http\Middleware\BlockDemoWrites;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['ingest.key' => AuthenticateIngestKey::class, 'demo.read-only' => BlockDemoWrites::class]);
        // Resolve the game before throttling so the ingest limit is counted per game, not per IP.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateIngestKey::class);
        $middleware->redirectGuestsTo('/login');

        // Behind a TLS-terminating proxy (Cloud Run, a load balancer), trust its forwarded headers
        // so generated URLs use https. Set TRUSTED_PROXIES to "*" or a comma-separated list.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
