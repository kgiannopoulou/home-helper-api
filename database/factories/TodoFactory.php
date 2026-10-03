<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
class TodoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => fake()->randomElement(['Call the plumber', 'Renew passport', 'Return library books', 'Book haircut']),
            'due_on' => fake()->optional()->dateTimeBetween('-5 days', '+20 days')?->format('Y-m-d'),
            'minutes' => fake()->optional()->randomElement([10, 15, 30, 60]),
        ];
    }
}
