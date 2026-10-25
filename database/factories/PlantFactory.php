<?php

namespace Database\Factories;

use App\Enums\LightRequirement;
use App\Enums\PlantLocation;
use App\Models\Plant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plant>
 */
class PlantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Monstera', 'Snake plant', 'Pothos', 'Peace lily', 'Aloe', 'Fiddle leaf fig']),
            'location_type' => PlantLocation::Indoor,
            'light_requirement' => fake()->randomElement(LightRequirement::cases()),
            'watering_interval_days' => fake()->randomElement([3, 7, 10, 14]),
            'water_amount_ml' => fake()->randomElement([150, 250, 350, 500]),
            'minimum_temperature_c' => 12,
            'maximum_temperature_c' => 32,
        ];
    }

    /** On the balcony, in the weather */
    public function outdoor(): static
    {
        return $this->state([
            'name' => 'Basil',
            'location_type' => PlantLocation::Balcony,
            'minimum_temperature_c' => 10,
            'maximum_temperature_c' => 32,
            'frost_sensitive' => true,
        ]);
    }
}
