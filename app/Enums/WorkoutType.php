<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Kinds of workout. */
enum WorkoutType: string
{
    use HasValues;

    case Run = 'run';
    case Walk = 'walk';
    case Gym = 'gym';
    case Cycle = 'cycle';
    case Swim = 'swim';
    case Hiit = 'hiit';
    case Yoga = 'yoga';
    case Other = 'other';
}
