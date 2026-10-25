<?php

namespace App\Weather;

/**
 * Where forecasts come from. Plant care only knows this interface, so the
 * vendor can change (and tests use a fake) without touching the care rules.
 */
interface WeatherProvider
{
    /**
     * The next 24 hours at this place.
     */
    public function forecast(float $latitude, float $longitude): WeatherForecast;
}
