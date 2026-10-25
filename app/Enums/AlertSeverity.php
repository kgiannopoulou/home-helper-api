<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** How much a plant alert matters, least to most. */
enum AlertSeverity: string
{
    use HasValues;

    // Water the Monstera today
    case Info = 'info';
    // Rain is coming, you can skip watering the basil
    case Recommendation = 'recommendation';
    // Move the basil inside tonight, 6°C expected
    case Warning = 'warning';
    // Frost in a few hours
    case Urgent = 'urgent';

    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }
}
