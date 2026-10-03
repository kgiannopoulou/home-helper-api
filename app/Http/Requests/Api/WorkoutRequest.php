<?php

namespace App\Http\Requests\Api;

use App\Enums\Intensity;
use App\Enums\WorkoutSource;
use App\Enums\WorkoutType;
use Illuminate\Validation\Rule;

class WorkoutRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::enum(WorkoutType::class)],
            'minutes' => ['required', 'integer', 'between:1,720'],
            'intensity' => ['sometimes', Rule::enum(Intensity::class)],
            'kcal' => ['required', 'integer', 'between:0,5000'],
            'source' => ['sometimes', Rule::enum(WorkoutSource::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
