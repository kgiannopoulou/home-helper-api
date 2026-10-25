<?php

namespace App\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * Forecasts from Open-Meteo (free, no API key): hourly temperature, rain and
 * gusts for the next 24 hours, reduced to the lows, highs and totals.
 */
class OpenMeteoWeatherProvider implements WeatherProvider
{
    public function forecast(float $latitude, float $longitude): WeatherForecast
    {
        $hourly = Http::acceptJson()->timeout(10)->retry(2, 500)
            ->get(config('services.open_meteo.url'), [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'hourly' => 'temperature_2m,precipitation,wind_gusts_10m',
                'forecast_hours' => 24,
                'timezone' => 'UTC',
                'wind_speed_unit' => 'kmh',
            ])
            ->throw()
            ->json('hourly');

        $times = $hourly['time'] ?? [];
        $temperatures = array_map(floatval(...), array_filter($hourly['temperature_2m'] ?? [], fn ($t) => $t !== null));
        if (! $times || ! $temperatures) {
            throw new UnexpectedValueException('Open-Meteo sent no hourly forecast.');
        }

        $at = fn (int $i) => CarbonImmutable::parse($times[$i], 'UTC');
        $low = array_keys($temperatures, min($temperatures))[0];
        $high = array_keys($temperatures, max($temperatures))[0];

        return new WeatherForecast(
            minimumTemperatureC: $temperatures[$low],
            maximumTemperatureC: $temperatures[$high],
            precipitationMm: round(array_sum(array_filter($hourly['precipitation'] ?? [])), 1),
            maxWindGustKmh: (float) max([0, ...array_filter($hourly['wind_gusts_10m'] ?? [])]),
            minimumAt: $at($low),
            maximumAt: $at($high),
        );
    }
}
