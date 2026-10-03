<?php

namespace App\Http\Resources;

use App\Models\FoodEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FoodEntry
 */
class FoodEntryResource extends JsonResource
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
            'eaten_at' => $this->eaten_at?->toIso8601String(),
            'name' => $this->name,
            'meal' => $this->meal?->value,
            'grams' => $this->grams,
            'kcal' => $this->kcal,
            'protein' => $this->protein === null ? null : (float) $this->protein,
            'carbs' => $this->carbs === null ? null : (float) $this->carbs,
            'fat' => $this->fat === null ? null : (float) $this->fat,
            'fiber' => $this->fiber === null ? null : (float) $this->fiber,
            'source' => $this->source?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
