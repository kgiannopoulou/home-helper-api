<?php

namespace App\Http\Resources;

use App\Models\SleepEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SleepEntry
 */
class SleepEntryResource extends JsonResource
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
            'bed_at' => $this->bed_at?->toIso8601String(),
            'woke_at' => $this->woke_at?->toIso8601String(),
            'hours' => $this->hours === null ? null : (float) $this->hours,
            'quality' => $this->quality,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
