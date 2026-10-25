<?php

namespace App\Http\Requests\Api;

use App\Enums\LightRequirement;
use App\Enums\PlantLocation;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;

class PlantRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        return [
            'room_id' => ['nullable', $this->inHousehold('rooms')],
            'name' => ['required', 'string', 'max:100'],
            'species' => ['nullable', 'string', 'max:100'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'location_type' => ['required', Rule::enum(PlantLocation::class)],
            'light_requirement' => ['nullable', Rule::enum(LightRequirement::class)],
            'watering_interval_days' => ['required', 'integer', 'between:1,365'],
            'water_amount_ml' => ['nullable', 'integer', 'between:1,100000'],
            'fertilize_every_days' => ['nullable', 'integer', 'between:1,365'],
            'repot_every_months' => ['nullable', 'integer', 'between:1,120'],
            'minimum_temperature_c' => ['nullable', 'numeric', 'between:-50,70'],
            'maximum_temperature_c' => ['nullable', 'numeric', 'between:-50,70',
                // Only when both are sent; the CHECK constraint covers a PATCH of one
                Rule::when(fn (Fluent $input) => is_numeric($input->get('minimum_temperature_c')), ['gte:minimum_temperature_c'])],
            'frost_sensitive' => ['sometimes', 'boolean'],
            'rain_sensitive' => ['sometimes', 'boolean'],
            'wind_sensitive' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
