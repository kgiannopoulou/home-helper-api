<?php

namespace Database\Factories;

use App\Enums\Intensity;
use App\Enums\WorkoutSource;
use App\Enums\WorkoutType;
use App\Models\Household;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workout>
 */
class WorkoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'date' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'type' => fake()->randomElement(WorkoutType::cases()),
            'minutes' => fake()->numberBetween(15, 90),
            'intensity' => fake()->randomElement(Intensity::cases()),
            'kcal' => fake()->numberBetween(80, 700),
            'source' => WorkoutSource::Manual,
        ];
    }
}
