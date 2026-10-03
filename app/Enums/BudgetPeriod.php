<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How often a budget resets. */
enum BudgetPeriod: string
{
    use HasValues;

    case Week = 'week';
    case Month = 'month';
}
