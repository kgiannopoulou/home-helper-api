<?php

namespace App\Http\Resources;

use App\Models\ShoppingItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShoppingItem
 */
class ShoppingItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'added_by' => $this->added_by,
            'name' => $this->name,
            'category' => $this->category?->value,
            'quantity' => $this->quantity,
            'price' => $this->price === null ? null : (float) $this->price,
            'checked' => $this->checked,
            'source' => $this->source?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
