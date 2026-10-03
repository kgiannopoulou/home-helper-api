<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => fake()->randomElement(['Dentist', 'Dinner with friends', 'Parents visit', 'Car service']),
            'starts_at' => $start = Carbon::instance(fake()->dateTimeBetween('-10 days', '+30 days'))->startOfHour(),
            'ends_at' => $start->copy()->addHour(),
            'all_day' => false,
        ];
    }
}
