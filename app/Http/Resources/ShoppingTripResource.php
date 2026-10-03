<?php

namespace App\Http\Resources;

use App\Models\ShoppingTrip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShoppingTrip
 */
class ShoppingTripResource extends JsonResource
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
            'store' => $this->store,
            'total' => $this->total === null ? null : (float) $this->total,
            'item_count' => $this->item_count,
            'source' => $this->source?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
