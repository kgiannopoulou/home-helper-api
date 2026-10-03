<?php

namespace App\Http\Requests\Api;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use Illuminate\Validation\Rule;

class ExpenseRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'source' => ['sometimes', Rule::enum(ExpenseSource::class)],
            'recurring_bill_id' => ['nullable', $this->inHousehold('recurring_bills')],
        ];
    }
}
