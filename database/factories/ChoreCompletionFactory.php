<?php

namespace Database\Factories;

use App\Models\Chore;
use App\Models\ChoreCompletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChoreCompletion>
 */
class ChoreCompletionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chore_id' => Chore::factory(),
            'household_id' => fn (array $a) => Chore::find($a['chore_id'])->household_id,
            'done_at' => fake()->dateTimeBetween('-30 days'),
            'minutes' => fake()->numberBetween(5, 45),
        ];
    }
}
