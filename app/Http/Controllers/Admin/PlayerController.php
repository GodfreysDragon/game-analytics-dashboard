<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\GameSessionResource;
use App\Http\Resources\PlayerResource;
use App\Models\Game;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlayerController extends Controller
{
    private const SORTS = [
        'last_seen' => 'last_seen_at',
        'first_seen' => 'first_seen_at',
        'events' => 'event_count',
        'sessions' => 'session_count',
        'ltv' => 'lifetime_value',
    ];

    public function index(Request $request, Game $game): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:128'],
            'platform' => ['nullable', 'string', Rule::in(config('analytics.platforms'))],
            'payers' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
        ]);

        $players = $game->players()
            ->when($filters['q'] ?? null, fn ($query, $search) => $query->whereRaw(
                "external_id LIKE ? ESCAPE '!'",
                [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'],
            ))
            ->when($filters['platform'] ?? null, fn ($query, $platform) => $query->where('platform', $platform))
            ->when($request->boolean('payers'), fn ($query) => $query->where('lifetime_value', '>', 0))
            ->orderByDesc(self::SORTS[$filters['sort'] ?? 'last_seen'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return PlayerResource::collection($players);
    }

    public function show(Game $game, Player $player): JsonResponse
    {
        $activeDays = DB::table('player_activity_days')
            ->where('player_id', $player->id)
            ->orderBy('activity_on')
            ->pluck('activity_on');

        $eventMix = DB::table('events')
            ->where('player_id', $player->id)
            ->groupBy('name')
            ->selectRaw('name, COUNT(*) AS total')
            ->orderByDesc('total')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => ['name' => $row->name, 'total' => (int) $row->total]);

        return response()->json([
            'player' => new PlayerResource($player),
            'active_days' => $activeDays,
            'event_mix' => $eventMix,
            'avg_session_seconds' => (int) round((float) $player->sessions()->avg('duration_seconds')),
            'sessions' => GameSessionResource::collection(
                $player->sessions()->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get(),
            ),
        ]);
    }

    public function timeline(Request $request, Game $game, Player $player): AnonymousResourceCollection
    {
        $request->validate(['name' => ['nullable', 'string', 'max:64']]);

        $events = $player->events()
            ->when($request->input('name'), fn ($query, $name) => $query->where('name', $name))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        return EventResource::collection($events);
    }
}
