<?php

namespace App\Http\Resources;

use App\Models\ChoreCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChoreCompletion
 */
class ChoreCompletionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chore_id' => $this->chore_id,
            'user_id' => $this->user_id,
            'done_at' => $this->done_at?->toIso8601String(),
            'minutes' => $this->minutes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
