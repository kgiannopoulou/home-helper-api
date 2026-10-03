<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Spending categories, the same as the phone's money module. */
enum ExpenseCategory: string
{
    use HasValues;

    case Groceries = 'groceries';
    case Household = 'household';
    case EatingOut = 'eating_out';
    case Transport = 'transport';
    case Bills = 'bills';
    case Health = 'health';
    case Fun = 'fun';
    case Shopping = 'shopping';
    case Other = 'other';
}
