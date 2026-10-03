<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category?->value,
            'location' => $this->location?->value,
            'level' => $this->level?->value,
            'quantity' => $this->quantity,
            'expires_on' => $this->expires_on?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
