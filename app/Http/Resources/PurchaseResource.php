<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Purchase
 */
class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'shopping_trip_id' => $this->shopping_trip_id,
            'bought_on' => $this->bought_on?->toDateString(),
            'price' => $this->price === null ? null : (float) $this->price,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
