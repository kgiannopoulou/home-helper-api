<?php

namespace App\Http\Requests\Api;

use App\Enums\PlantCareType;
use Illuminate\Validation\Rule;

class PlantCareLogRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'plant_id' => ['required', $this->inHousehold('plants')],
            'user_id' => ['sometimes', $this->member()],
            'type' => ['required', Rule::enum(PlantCareType::class)],
            'amount_ml' => ['nullable', 'integer', 'between:1,100000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'performed_at' => ['required', 'date'],
        ];
    }
}
