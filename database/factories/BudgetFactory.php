<?php

namespace Database\Factories;

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Models\Budget;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'period' => BudgetPeriod::Month,
            // One category each, so several budgets fit in a household
            'category' => fake()->unique()->randomElement(ExpenseCategory::cases()),
            'amount' => fake()->numberBetween(50, 400),
        ];
    }

    /**
     * The budget for all spending in the period.
     */
    public function overall(): static
    {
        return $this->state(['category' => null, 'amount' => 1500]);
    }
}
