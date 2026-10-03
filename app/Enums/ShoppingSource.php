<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Who put an item on the shopping list. */
enum ShoppingSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Inventory = 'inventory';
}
