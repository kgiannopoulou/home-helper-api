<?php

namespace App\Http\Resources;

use App\Models\RecurringBill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecurringBill
 */
class RecurringBillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'category' => $this->category?->value,
            'day_of_month' => $this->day_of_month,
            'active' => $this->active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
