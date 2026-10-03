<?php

namespace App\Http\Requests\Api;

use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use Illuminate\Validation\Rule;

class ShoppingItemRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::enum(ItemCategory::class)],
            'quantity' => ['nullable', 'string', 'max:50'],
            'price' => [...$this->money(required: false)],
            'checked' => ['sometimes', 'boolean'],
            'source' => ['sometimes', Rule::enum(ShoppingSource::class)],
        ];
    }
}
