<?php

namespace App\Http\Requests\Api;

class WeightRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'kg' => ['required', 'numeric', 'between:20,400'],
        ];
    }
}
