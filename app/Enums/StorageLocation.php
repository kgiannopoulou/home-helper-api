<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Where a kitchen item is kept. */
enum StorageLocation: string
{
    use HasValues;

    case Fridge = 'fridge';
    case Freezer = 'freezer';
    case Pantry = 'pantry';
    case Bathroom = 'bathroom';
    case Cleaning = 'cleaning';
    case Other = 'other';
}
