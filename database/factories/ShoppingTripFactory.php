<?php

namespace Database\Factories;

use App\Enums\TripSource;
use App\Models\Household;
use App\Models\ShoppingTrip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingTrip>
 */
class ShoppingTripFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'date' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'store' => fake()->randomElement(['Lidl', 'AB Vassilopoulos', 'Sklavenitis', 'My Market']),
            'total' => fake()->randomFloat(2, 10, 140),
            'item_count' => fake()->numberBetween(3, 30),
            'source' => TripSource::Manual,
        ];
    }
}
