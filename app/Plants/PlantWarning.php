<?php

namespace App\Plants;

use App\Enums\AlertSeverity;
use App\Enums\PlantAlertType;

/**
 * Something a plant needs right now, before it becomes a stored alert.
 */
final readonly class PlantWarning
{
    /**
     * @param  array<string, mixed>  $metadata  the numbers behind it
     */
    public function __construct(
        public PlantAlertType $type,
        public string $title,
        public string $message,
        public array $metadata = [],
    ) {}

    public function severity(): AlertSeverity
    {
        return $this->type->severity();
    }
}
