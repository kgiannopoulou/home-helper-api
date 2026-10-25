<?php

namespace Tests\Support;

use App\Weather\WeatherForecast;
use App\Weather\WeatherProvider;
use RuntimeException;

/**
 * A forecast set by the test, with no request to a weather service. Counts
 * the calls, so a test can prove the forecast is fetched once per household.
 */
class FakeWeatherProvider implements WeatherProvider
{
    public int $calls = 0;

    public bool $down = false;

    public function __construct(public WeatherForecast $forecast = new WeatherForecast(14, 24)) {}

    public function forecast(float $latitude, float $longitude): WeatherForecast
    {
        $this->calls++;
        if ($this->down) {
            throw new RuntimeException('Weather service is down');
        }

        return $this->forecast;
    }
}
