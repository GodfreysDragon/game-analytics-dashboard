<?php

namespace App\Http\Resources;

use App\Models\Funnel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Funnel
 */
class FunnelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'steps' => $this->steps,
            'window_minutes' => $this->window_minutes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
