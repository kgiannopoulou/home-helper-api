<?php

namespace App\Http\Resources;

use App\Models\Supply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Supply
 */
class SupplyResource extends JsonResource
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
            'level' => $this->level?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
