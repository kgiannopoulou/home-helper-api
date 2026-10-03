<?php

namespace App\Http\Requests\Api;

use App\Enums\Drink;
use Illuminate\Validation\Rule;

class WaterEntryRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'drunk_at' => ['required', 'date'],
            'ml' => ['required', 'integer', 'between:1,3000'],
            'drink' => ['sometimes', Rule::enum(Drink::class)],
        ];
    }
}
