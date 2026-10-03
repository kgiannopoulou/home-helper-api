<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** What a member may do in a household. */
enum HouseholdRole: string
{
    use HasValues;

    case Owner = 'owner';
    case Member = 'member';
}
