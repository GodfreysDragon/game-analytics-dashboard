<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'session_key' => $this->session_key,
            'value' => $this->value,
            'properties' => $this->properties ?? (object) [],
            'occurred_at' => $this->occurred_at->toIso8601ZuluString('millisecond'),
            'received_at' => $this->received_at->toIso8601ZuluString('millisecond'),
            'player' => $this->whenLoaded('player', fn () => [
                'id' => $this->player->id,
                'external_id' => $this->player->external_id,
                'platform' => $this->player->platform,
            ]),
        ];
    }
}
