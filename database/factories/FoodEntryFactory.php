<?php

namespace Database\Factories;

use App\Enums\FoodSource;
use App\Enums\Meal;
use App\Models\FoodEntry;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodEntry>
 */
class FoodEntryFactory extends Factory
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
            'eaten_at' => $date.' 13:00:00',
            'name' => fake()->randomElement(['Greek yoghurt', 'Chicken salad', 'Lentil soup', 'Toast with feta', 'Apple']),
            'meal' => fake()->randomElement(Meal::cases()),
            'grams' => fake()->numberBetween(80, 400),
            'kcal' => fake()->numberBetween(80, 750),
            'protein' => fake()->randomFloat(1, 0, 45),
            'carbs' => fake()->randomFloat(1, 0, 90),
            'fat' => fake()->randomFloat(1, 0, 35),
            'fiber' => fake()->randomFloat(1, 0, 12),
            'source' => FoodSource::Database,
        ];
    }
}
