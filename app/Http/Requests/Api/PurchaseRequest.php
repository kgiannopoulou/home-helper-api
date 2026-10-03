<?php

namespace App\Http\Requests\Api;

class PurchaseRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'inventory_item_id' => ['required', $this->inHousehold('inventory_items')],
            'shopping_trip_id' => ['nullable', $this->inHousehold('shopping_trips')],
            'bought_on' => ['required', 'date_format:Y-m-d'],
            'price' => [...$this->money(required: false)],
        ];
    }
}
