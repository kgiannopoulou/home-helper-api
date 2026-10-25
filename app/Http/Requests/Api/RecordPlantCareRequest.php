<?php

namespace App\Http\Requests\Api;

use App\Enums\PlantCareType;
use App\Enums\PlantLocation;
use Illuminate\Validation\Rule;

/**
 * One care action on a plant (POST …/plants/{plant}/water). The plant and the
 * type come from the URL; the time defaults to now.
 */
class RecordPlantCareRequest extends HouseholdDataRequest
{
    protected function fields(): array
    {
        $moving = $this->route('type') === PlantCareType::Moved->value;

        return [
            'amount_ml' => ['nullable', 'integer', 'between:1,100000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'performed_at' => ['sometimes', 'date', 'before_or_equal:+5 minutes'],
            // Moving a plant says where to: inside, out of the weather, another room
            ...($moving ? [
                'location_type' => ['required_without:room_id', Rule::enum(PlantLocation::class)],
                'room_id' => ['nullable', $this->inHousehold('rooms')],
            ] : []),
        ];
    }
}
