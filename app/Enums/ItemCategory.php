<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Shared item categories (homeCore.ts on the phone). */
enum ItemCategory: string
{
    use HasValues;

    case Food = 'food';
    case Drinks = 'drinks';
    case Cleaning = 'cleaning';
    case Bathroom = 'bathroom';
    case Home = 'home';
    case Pet = 'pet';
    case Other = 'other';
}
