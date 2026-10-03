<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->unique()->randomElement(['Kitchen', 'Bathroom', 'Living room', 'Bedroom', 'Hallway', 'Balcony', 'Office']),
            'emoji' => '🏠',
            'personal' => false,
        ];
    }
}
