<?php

namespace App\Http\Requests\Api;

class SleepEntryRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'bed_at' => ['required', 'required_with:woke_at', 'date'],
            'woke_at' => ['required', 'required_with:bed_at', 'date', 'after:bed_at'],
            'quality' => ['required', 'integer', 'between:1,5'],
        ];
    }
}
