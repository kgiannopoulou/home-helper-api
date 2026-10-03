<?php

namespace Database\Factories;

use App\Models\Chore;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chore>
 */
class ChoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'household_id' => fn (array $a) => Room::find($a['room_id'])->household_id,
            'name' => fake()->randomElement(['Vacuum', 'Mop floor', 'Dust', 'Wipe surfaces', 'Clean mirror', 'Take out bins']),
            'every_days' => fake()->randomElement([1, 3, 7, 14, 30]),
            'minutes' => fake()->numberBetween(5, 45),
            'fixed_frequency' => false,
        ];
    }
}
