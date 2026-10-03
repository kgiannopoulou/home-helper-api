<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How much of a cleaning supply is left. */
enum SupplyLevel: string
{
    use HasValues;

    case Full = 'full';
    case Half = 'half';
    case Low = 'low';
    case Out = 'out';
}
