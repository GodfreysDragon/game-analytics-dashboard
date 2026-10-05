<?php

namespace App\Http\Middleware;

use App\Models\Game;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the game from its ingest key, sent as "Authorization: Bearer gk_..." or "X-Game-Key".
 */
class AuthenticateIngestKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken() ?? $request->header('X-Game-Key');

        $game = is_string($key) && str_starts_with($key, Game::KEY_PREFIX)
            ? Game::findByIngestKey($key)
            : null;

        if ($game === null) {
            return response()->json(['message' => 'Invalid or missing ingest key.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('game', $game);

        return $next($request);
    }
}
