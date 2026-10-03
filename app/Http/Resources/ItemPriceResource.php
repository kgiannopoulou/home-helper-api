<?php

namespace App\Http\Resources;

use App\Models\ItemPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ItemPrice
 */
class ItemPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shopping_trip_id' => $this->shopping_trip_id,
            'name' => $this->name,
            'price' => $this->price === null ? null : (float) $this->price,
            'seen_on' => $this->seen_on?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
