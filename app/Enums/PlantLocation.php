<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Where a plant lives, which decides whether the weather reaches it. */
enum PlantLocation: string
{
    use HasValues;

    case Indoor = 'indoor';
    case Outdoor = 'outdoor';
    case Balcony = 'balcony';
    case Greenhouse = 'greenhouse';

    /** Out in the weather: frost, heat, rain and wind apply */
    public function exposed(): bool
    {
        return $this === self::Outdoor || $this === self::Balcony;
    }
}
