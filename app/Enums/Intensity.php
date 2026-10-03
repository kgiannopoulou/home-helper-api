<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How hard a workout was. */
enum Intensity: string
{
    use HasValues;

    case Easy = 'easy';
    case Moderate = 'moderate';
    case Hard = 'hard';
}
