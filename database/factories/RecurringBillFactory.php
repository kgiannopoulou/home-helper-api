<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Models\Household;
use App\Models\RecurringBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringBill>
 */
class RecurringBillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->unique()->randomElement(['Rent', 'Electricity', 'Phone', 'Internet', 'Netflix', 'Gym', 'Insurance', 'Water']),
            'amount' => fake()->randomFloat(2, 8, 600),
            'category' => ExpenseCategory::Bills,
            'day_of_month' => fake()->numberBetween(1, 28),
            'active' => true,
        ];
    }
}
