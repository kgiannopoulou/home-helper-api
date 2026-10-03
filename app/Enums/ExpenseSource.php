<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Where an expense came from. */
enum ExpenseSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Shopping = 'shopping';
    case Recurring = 'recurring';
}
