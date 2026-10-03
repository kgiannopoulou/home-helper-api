<?php

namespace App\Http\Requests\Api;

use App\Enums\AdminKind;
use Illuminate\Validation\Rule;

class AdminItemRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::enum(AdminKind::class)],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'repeat_months' => ['sometimes', 'integer', 'between:0,120'],
            'remind_days' => ['sometimes', 'integer', 'between:0,365'],
            'amount' => [...$this->money(required: false)],
            'done_at' => ['nullable', 'date'],
        ];
    }
}
