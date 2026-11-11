<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** What kind of thing the household asked Home Helper to remember (docs/memory.md#facts). */
enum FactKind: string
{
    use HasValues;

    /** "The boiler was serviced in March 2026" */
    case Fact = 'fact';
    /** "Alex doesn't eat meat", "we prefer Lidl" */
    case Preference = 'preference';
    /** "Bins go out on Tuesday nights" */
    case Routine = 'routine';
}
