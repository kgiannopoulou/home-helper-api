<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How a shopping trip was recorded. */
enum TripSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Receipt = 'receipt';
}
