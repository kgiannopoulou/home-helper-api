<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use App\Models\Expense;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'date' => fake()->dateTimeBetween('-60 days')->format('Y-m-d'),
            'amount' => fake()->randomFloat(2, 2, 120),
            'category' => fake()->randomElement(ExpenseCategory::cases()),
            'note' => fake()->optional()->words(3, true),
            'source' => ExpenseSource::Manual,
        ];
    }
}
