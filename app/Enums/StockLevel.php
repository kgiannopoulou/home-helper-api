<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How much of an inventory item is left. */
enum StockLevel: string
{
    use HasValues;

    case Full = 'full';
    case Half = 'half';
    case Low = 'low';
    case Empty = 'empty';
}
