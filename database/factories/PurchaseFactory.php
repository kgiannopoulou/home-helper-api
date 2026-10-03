<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Purchase>
 */
class PurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'household_id' => fn (array $a) => InventoryItem::find($a['inventory_item_id'])->household_id,
            'bought_on' => fake()->dateTimeBetween('-90 days')->format('Y-m-d'),
            'price' => fake()->randomFloat(2, 0.5, 15),
        ];
    }
}
