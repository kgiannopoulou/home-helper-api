<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Why a plant needs attention. */
enum PlantAlertType: string
{
    use HasValues;

    case WateringDue = 'watering_due';
    case WateringOverdue = 'watering_overdue';
    // Due, but enough rain is forecast for an outdoor plant
    case WateringPostponed = 'watering_postponed';
    case FertilizingDue = 'fertilizing_due';
    case RepottingDue = 'repotting_due';
    case FrostWarning = 'frost_warning';
    case MoveInside = 'move_inside';
    case MoveToShade = 'move_to_shade';
    case HeavyRain = 'heavy_rain';
    case StrongWind = 'strong_wind';

    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::WateringDue, self::FertilizingDue, self::RepottingDue => AlertSeverity::Info,
            self::WateringPostponed => AlertSeverity::Recommendation,
            self::WateringOverdue, self::MoveInside, self::MoveToShade, self::HeavyRain, self::StrongWind => AlertSeverity::Warning,
            self::FrostWarning => AlertSeverity::Urgent,
        };
    }

    /** Comes from the forecast, not the care schedule */
    public function weather(): bool
    {
        return in_array($this, [self::FrostWarning, self::MoveInside, self::MoveToShade, self::HeavyRain, self::StrongWind], true);
    }

    /** Worth a push. Repotting can wait for the next time someone opens the app. */
    public function pushes(): bool
    {
        return $this !== self::RepottingDue;
    }
}
