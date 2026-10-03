<?php

namespace App\Http\Resources;

use App\Models\Weight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Weight
 */
class WeightResource extends JsonResource
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
            'kg' => $this->kg === null ? null : (float) $this->kg,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
