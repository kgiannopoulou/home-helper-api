<?php

namespace App\Http\Requests\Api;

class RoomRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'emoji' => ['sometimes', 'string', 'max:16'],
            'personal' => ['sometimes', 'boolean'],
        ];
    }
}
