<?php

namespace App\Http\Requests\Api;

use App\Enums\ItemCategory;
use App\Enums\SupplyLevel;
use Illuminate\Validation\Rule;

class SupplyRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'category' => ['sometimes', Rule::enum(ItemCategory::class)],
            'level' => ['sometimes', Rule::enum(SupplyLevel::class)],
        ];
    }
}
