<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\User;
use App\Models\Weight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Weight>
 */
class WeightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'date' => fake()->unique()->dateTimeBetween('-180 days')->format('Y-m-d'),
            'kg' => fake()->randomFloat(1, 55, 95),
        ];
    }
}
