<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Where a food entry's nutrients came from. */
enum FoodSource: string
{
    use HasValues;

    case Database = 'database';
    case Custom = 'custom';
    case Ai = 'ai';
}
