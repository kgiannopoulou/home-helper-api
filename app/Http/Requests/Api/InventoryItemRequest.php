<?php

namespace App\Http\Requests\Api;

use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Kitchen\Units;
use Illuminate\Validation\Rule;

class InventoryItemRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::enum(ItemCategory::class)],
            'location' => ['required', Rule::enum(StorageLocation::class)],
            'level' => ['sometimes', Rule::enum(StockLevel::class)],
            'quantity' => ['nullable', 'string', 'max:50'],
            // Phase 7 (docs/kitchen.md#amounts-and-units): a measured amount in a base unit
            'ingredient' => ['nullable', 'string', 'max:60'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unit' => ['nullable', 'required_with:amount', Rule::in(Units::BASE)],
            'full_amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
