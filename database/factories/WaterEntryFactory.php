<?php

namespace Database\Factories;

use App\Enums\Drink;
use App\Models\Household;
use App\Models\User;
use App\Models\WaterEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaterEntry>
 */
class WaterEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'date' => $date = fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'drunk_at' => $date.' 10:00:00',
            'ml' => fake()->randomElement([250, 330, 500]),
            'drink' => Drink::Water,
        ];
    }
}
