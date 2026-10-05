<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventStreamController extends Controller
{
    /**
     * Most recently received events, newest first. Pass ?after_id= to fetch only what arrived
     * since the last poll (the live tail), or follow the cursor links to page back in time.
     */
    public function __invoke(Request $request, Game $game): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'name' => ['nullable', 'string', 'max:64'],
            'player' => ['nullable', 'string', 'max:128'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $events = $game->events()
            ->with('player:id,external_id,platform')
            ->when($filters['name'] ?? null, fn ($query, $name) => $query->where('name', $name))
            ->when($filters['player'] ?? null, fn ($query, $externalId) => $query->whereRelation('player', 'external_id', $externalId))
            ->when(isset($filters['after_id']), fn ($query) => $query->where('id', '>', (int) $filters['after_id']))
            ->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        return EventResource::collection($events);
    }
}
