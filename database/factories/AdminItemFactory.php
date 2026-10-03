<?php

namespace Database\Factories;

use App\Enums\AdminKind;
use App\Models\AdminItem;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminItem>
 */
class AdminItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => fake()->randomElement(['Car insurance', 'Dentist check-up', 'Boiler service', 'Phone contract']),
            'kind' => fake()->randomElement(AdminKind::cases()),
            'due_on' => fake()->dateTimeBetween('now', '+90 days')->format('Y-m-d'),
            'repeat_months' => fake()->randomElement([0, 6, 12]),
            'remind_days' => 7,
        ];
    }
}
