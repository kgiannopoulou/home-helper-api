<?php

namespace Database\Factories;

use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Models\Household;
use App\Models\ShoppingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingItem>
 */
class ShoppingItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => ucfirst(fake()->word()),
            'category' => fake()->randomElement(ItemCategory::cases()),
            'quantity' => fake()->optional()->randomElement(['1', '2', '1 kg', '6 pack']),
            'checked' => false,
            'source' => ShoppingSource::Manual,
        ];
    }
}
