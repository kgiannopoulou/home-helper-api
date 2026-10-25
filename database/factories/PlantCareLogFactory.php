<?php

namespace Database\Factories;

use App\Enums\PlantCareType;
use App\Models\Plant;
use App\Models\PlantCareLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlantCareLog>
 */
class PlantCareLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plant_id' => Plant::factory(),
            'household_id' => fn (array $a) => Plant::find($a['plant_id'])->household_id,
            'type' => PlantCareType::Watered,
            'amount_ml' => 300,
            'performed_at' => fake()->dateTimeBetween('-10 days'),
        ];
    }
}
