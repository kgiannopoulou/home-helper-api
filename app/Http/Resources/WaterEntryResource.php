<?php

namespace App\Http\Resources;

use App\Models\WaterEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WaterEntry
 */
class WaterEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'date' => $this->date?->toDateString(),
            'drunk_at' => $this->drunk_at?->toIso8601String(),
            'ml' => $this->ml,
            'drink' => $this->drink?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
