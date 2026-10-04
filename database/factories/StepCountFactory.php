<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\StepCount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StepCount>
 */
class StepCountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'date' => fake()->unique()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'steps' => fake()->numberBetween(1500, 14000),
        ];
    }
}
