<?php

namespace Database\Factories;

use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Household;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'category' => ItemCategory::Food,
            'location' => fake()->randomElement([StorageLocation::Fridge, StorageLocation::Pantry, StorageLocation::Freezer]),
            'level' => fake()->randomElement(StockLevel::cases()),
            'quantity' => null,
            'expires_on' => fake()->optional()->dateTimeBetween('now', '+30 days')?->format('Y-m-d'),
        ];
    }
}
