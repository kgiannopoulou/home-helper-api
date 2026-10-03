<?php

namespace App\Http\Requests\Api;

class EventRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'required_with:ends_at', 'date'],
            'ends_at' => ['required', 'required_with:starts_at', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:150'],
        ];
    }
}
