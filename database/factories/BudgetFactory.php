<?php

namespace Database\Factories;

use App\Enums\BudgetPeriod;
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
            'category' => null,
            'amount' => 1500,
        ];
    }
}
