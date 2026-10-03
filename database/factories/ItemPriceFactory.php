<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\ItemPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemPrice>
 */
class ItemPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->word(),
            'price' => fake()->randomFloat(2, 0.5, 15),
            'seen_on' => fake()->dateTimeBetween('-90 days')->format('Y-m-d'),
        ];
    }
}
