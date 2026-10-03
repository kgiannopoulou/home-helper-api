<?php

namespace Database\Factories;

use App\Enums\ItemCategory;
use App\Enums\SupplyLevel;
use App\Models\Household;
use App\Models\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supply>
 */
class SupplyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->unique()->randomElement(['Dish soap', 'Bleach', 'Sponges', 'Bin bags', 'Glass cleaner', 'Toilet paper']),
            'category' => ItemCategory::Cleaning,
            'level' => fake()->randomElement(SupplyLevel::cases()),
        ];
    }
}
