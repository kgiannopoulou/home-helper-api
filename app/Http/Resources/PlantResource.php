<?php

namespace App\Http\Resources;

use App\Models\Plant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plant
 */
class PlantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'species' => $this->species,
            'nickname' => $this->nickname,
            'location_type' => $this->location_type->value,
            'light_requirement' => $this->light_requirement?->value,
            'watering_interval_days' => $this->watering_interval_days,
            'water_amount_ml' => $this->water_amount_ml,
            'fertilize_every_days' => $this->fertilize_every_days,
            'repot_every_months' => $this->repot_every_months,
            'minimum_temperature_c' => $this->minimum_temperature_c,
            'maximum_temperature_c' => $this->maximum_temperature_c,
            'frost_sensitive' => $this->frost_sensitive,
            'rain_sensitive' => $this->rain_sensitive,
            'wind_sensitive' => $this->wind_sensitive,
            'notes' => $this->notes,
            // Kept by the server from the care logs; read-only
            'last_watered_at' => $this->last_watered_at?->toIso8601String(),
            'next_watering_at' => $this->next_watering_at?->toIso8601String(),
            'last_fertilized_at' => $this->last_fertilized_at?->toIso8601String(),
            'next_fertilizing_at' => $this->next_fertilizing_at?->toIso8601String(),
            'last_repotted_at' => $this->last_repotted_at?->toIso8601String(),
            'next_repotting_at' => $this->next_repotting_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
