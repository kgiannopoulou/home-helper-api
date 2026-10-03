<?php

namespace App\Http\Resources;

use App\Models\Chore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Chore
 */
class ChoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'assignee_id' => $this->assignee_id,
            'name' => $this->name,
            'every_days' => $this->every_days,
            'minutes' => $this->minutes,
            'learned_from_days' => $this->learned_from_days,
            'learned_on' => $this->learned_on?->toDateString(),
            'fixed_frequency' => $this->fixed_frequency,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
