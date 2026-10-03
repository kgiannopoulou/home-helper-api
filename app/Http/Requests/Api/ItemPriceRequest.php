<?php

namespace App\Http\Requests\Api;

class ItemPriceRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'shopping_trip_id' => ['nullable', $this->inHousehold('shopping_trips')],
            'name' => ['required', 'string', 'max:100'],
            'price' => [...$this->money()],
            'seen_on' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
