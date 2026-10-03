<?php

namespace App\Http\Resources;

use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Budget
 */
class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period?->value,
            'category' => $this->category?->value,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
