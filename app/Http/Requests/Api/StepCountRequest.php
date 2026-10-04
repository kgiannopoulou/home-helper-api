<?php

namespace App\Http\Requests\Api;

class StepCountRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'steps' => ['required', 'integer', 'between:0,200000'],
        ];
    }
}
