<?php

namespace App\Http\Resources;

use App\Models\GameSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GameSession
 */
class GameSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_key' => $this->session_key,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'event_count' => $this->event_count,
        ];
    }
}
