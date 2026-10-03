<?php

namespace App\Http\Requests\Api;

use App\Enums\ExpenseCategory;
use Illuminate\Validation\Rule;

class RecurringBillRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
