<?php

namespace App\Http\Requests\Api;

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use Illuminate\Validation\Rule;

class BudgetRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'period' => ['required', Rule::enum(BudgetPeriod::class)],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'amount' => [...$this->money()],
        ];
    }
}
