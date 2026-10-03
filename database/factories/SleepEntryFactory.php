<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\SleepEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SleepEntry>
 */
class SleepEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'user_id' => User::factory(),
            'date' => ($woke = Carbon::instance(fake()->dateTimeBetween('-60 days'))->setTime(7, fake()->numberBetween(0, 59)))->toDateString(),
            'bed_at' => $woke->copy()->subMinutes(fake()->numberBetween(330, 540)),
            'woke_at' => $woke,
            'quality' => fake()->numberBetween(1, 5),
        ];
    }
}
