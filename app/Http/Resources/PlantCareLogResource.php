<?php

namespace App\Http\Resources;

use App\Models\PlantCareLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlantCareLog
 */
class PlantCareLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plant_id' => $this->plant_id,
            'user_id' => $this->user_id,
            'type' => $this->type->value,
            'amount_ml' => $this->amount_ml,
            'notes' => $this->notes,
            'performed_at' => $this->performed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
