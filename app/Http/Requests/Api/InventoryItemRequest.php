<?php

namespace App\Http\Requests\Api;

use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
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
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
