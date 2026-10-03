<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Drink types for water entries. */
enum Drink: string
{
    use HasValues;

    case Water = 'water';
    case Tea = 'tea';
    case Coffee = 'coffee';
    case Other = 'other';
}
