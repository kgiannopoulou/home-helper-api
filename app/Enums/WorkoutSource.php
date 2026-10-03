<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Whether a workout was logged by hand or by the running coach. */
enum WorkoutSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Coach = 'coach';
}
