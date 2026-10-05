<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'ingest_key_prefix' => $this->ingest_key_prefix,
            'ingest_key_rotated_at' => $this->ingest_key_rotated_at?->toIso8601String(),
            'players_count' => $this->whenCounted('players'),
            'last_event_at' => $this->whenHas('last_event_at'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
