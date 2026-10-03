<?php

namespace App\Http\Requests\Api;

class ChoreRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'room_id' => ['required', $this->inHousehold('rooms')],
            'assignee_id' => ['nullable', $this->member()],
            'name' => ['required', 'string', 'max:100'],
            'every_days' => ['required', 'integer', 'between:1,365'],
            'minutes' => ['required', 'integer', 'between:1,600'],
            'learned_from_days' => ['nullable', 'integer', 'between:1,365'],
            'learned_on' => ['nullable', 'date_format:Y-m-d'],
            'fixed_frequency' => ['sometimes', 'boolean'],
        ];
    }
}
