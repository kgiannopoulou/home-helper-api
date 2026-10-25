<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How much light a plant wants. */
enum LightRequirement: string
{
    use HasValues;

    case Low = 'low';
    case Medium = 'medium';
    case BrightIndirect = 'bright_indirect';
    case Direct = 'direct';
}
