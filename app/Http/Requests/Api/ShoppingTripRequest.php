<?php

namespace App\Http\Requests\Api;

use App\Enums\TripSource;
use Illuminate\Validation\Rule;

class ShoppingTripRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'store' => ['nullable', 'string', 'max:100'],
            'total' => [...$this->money()],
            'item_count' => ['sometimes', 'integer', 'between:0,1000'],
            'source' => ['sometimes', Rule::enum(TripSource::class)],
        ];
    }
}
