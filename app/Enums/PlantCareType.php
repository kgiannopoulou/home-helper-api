<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/** Something done to a plant, logged in plant_care_logs. */
enum PlantCareType: string
{
    use HasValues;

    case Watered = 'watered';
    case Fertilized = 'fertilized';
    case Pruned = 'pruned';
    case Repotted = 'repotted';
    case Misted = 'misted';
    case Moved = 'moved';
    // A health note: yellow leaves, pests, new growth…
    case Inspected = 'inspected';

    /** The action's URL: POST …/plants/{plant}/water */
    public function verb(): string
    {
        return match ($this) {
            self::Watered => 'water',
            self::Fertilized => 'fertilize',
            self::Pruned => 'prune',
            self::Repotted => 'repot',
            self::Misted => 'mist',
            self::Moved => 'move',
            self::Inspected => 'inspect',
        };
    }

    /** The plants column that keeps when this was last done, for the care that has a schedule */
    public function lastColumn(): ?string
    {
        return match ($this) {
            self::Watered => 'last_watered_at',
            self::Fertilized => 'last_fertilized_at',
            self::Repotted => 'last_repotted_at',
            default => null,
        };
    }
}
