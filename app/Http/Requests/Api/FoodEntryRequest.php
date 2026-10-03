<?php

namespace App\Http\Requests\Api;

use App\Enums\FoodSource;
use App\Enums\Meal;
use Illuminate\Validation\Rule;

class FoodEntryRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'eaten_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:100'],
            'meal' => ['required', Rule::enum(Meal::class)],
            'grams' => ['nullable', 'integer', 'between:1,5000'],
            'kcal' => ['required', 'integer', 'between:0,5000'],
            'protein' => ['sometimes', 'numeric', 'between:0,999'],
            'carbs' => ['sometimes', 'numeric', 'between:0,999'],
            'fat' => ['sometimes', 'numeric', 'between:0,999'],
            'fiber' => ['sometimes', 'numeric', 'between:0,999'],
            'source' => ['sometimes', Rule::enum(FoodSource::class)],
        ];
    }
}
