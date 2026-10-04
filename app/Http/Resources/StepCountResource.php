<?php

namespace App\Http\Resources;

use App\Models\StepCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StepCount
 */
class StepCountResource extends JsonResource
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
            'steps' => $this->steps,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
