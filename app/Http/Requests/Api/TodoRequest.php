<?php

namespace App\Http\Requests\Api;

class TodoRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'minutes' => ['nullable', 'integer', 'between:1,1440'],
            'done_at' => ['nullable', 'date'],
        ];
    }
}
