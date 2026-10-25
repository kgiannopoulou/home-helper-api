<?php

namespace App\Weather;

use Carbon\CarbonImmutable;

/**
 * The next 24 hours at one place, reduced to what plant care decides on.
 */
final readonly class WeatherForecast
{
    public function __construct(
        public float $minimumTemperatureC,
        public float $maximumTemperatureC,
        public float $precipitationMm = 0,
        public float $maxWindGustKmh = 0,
        // When the low and the high come, for "around 04:00" in a message
        public ?CarbonImmutable $minimumAt = null,
        public ?CarbonImmutable $maximumAt = null,
    ) {}

    /**
     * @return array<string, float|string|null>
     */
    public function toArray(): array
    {
        return [
            'minimum_temperature_c' => $this->minimumTemperatureC,
            'minimum_at' => $this->minimumAt?->toIso8601String(),
            'maximum_temperature_c' => $this->maximumTemperatureC,
            'maximum_at' => $this->maximumAt?->toIso8601String(),
            'precipitation_mm' => $this->precipitationMm,
            'max_wind_gust_kmh' => $this->maxWindGustKmh,
        ];
    }
}
