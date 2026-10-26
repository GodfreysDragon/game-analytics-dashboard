<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class GameController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $games = Game::query()
            ->visible()
            ->withCount('players')
            ->withMax('events as last_event_at', 'received_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return GameResource::collection($games);
    }

    /**
     * Register a game. The plaintext ingest key is only ever returned in this response.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:games,name'],
        ]);

        $game = new Game(['name' => $data['name'], 'slug' => $this->uniqueSlug($data['name'])]);
        $key = $game->rotateIngestKey();

        return (new GameResource($game))
            ->additional(['ingest_key' => $key])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Replace the ingest key; the old key stops working immediately.
     */
    public function rotateKey(Game $game): JsonResponse
    {
        $key = $game->rotateIngestKey();

        return (new GameResource($game))->additional(['ingest_key' => $key])->response();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'game';
        $slug = $base;

        for ($suffix = 2; Game::where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
