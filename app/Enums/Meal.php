<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Meal slots for food entries. */
enum Meal: string
{
    use HasValues;

    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Snack = 'snack';
}
