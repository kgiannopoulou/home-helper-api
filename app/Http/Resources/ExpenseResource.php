<?php

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'recurring_bill_id' => $this->recurring_bill_id,
            'date' => $this->date?->toDateString(),
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'category' => $this->category?->value,
            'note' => $this->note,
            'source' => $this->source?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
