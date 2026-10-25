<?php

namespace App\Http\Resources;

use App\Models\PlantAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlantAlert
 */
class PlantAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plant_id' => $this->plant_id,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'message' => $this->message,
            'metadata' => $this->metadata,
            'triggered_at' => $this->triggered_at?->toIso8601String(),
            'pushed_at' => $this->pushed_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
