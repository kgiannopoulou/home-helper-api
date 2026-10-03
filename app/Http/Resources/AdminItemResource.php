<?php

namespace App\Http\Resources;

use App\Models\AdminItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdminItem
 */
class AdminItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind?->value,
            'due_on' => $this->due_on?->toDateString(),
            'repeat_months' => $this->repeat_months,
            'remind_days' => $this->remind_days,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'done_at' => $this->done_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
