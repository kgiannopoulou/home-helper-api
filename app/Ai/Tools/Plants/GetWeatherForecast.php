<?php

namespace App\Ai\Tools\Plants;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Plants\PlantCareService;

class GetWeatherForecast extends Tool
{
    public function __construct(private PlantCareService $care) {}

    public function name(): string
    {
        return 'get_weather_forecast';
    }

    public function label(): string
    {
        return 'Checked the weather';
    }

    public function description(): string
    {
        return 'The next 24 hours of weather at the household (low and high with their times, rain in mm, strongest gust), from Open-Meteo. Only available when the household\'s location is set; otherwise say that the owner can set it.';
    }

    public function run(array $input, ToolContext $context): array
    {
        if (! $context->household->hasLocation()) {
            return ['available' => false, 'reason' => 'The household has no location set. The owner can set it in the household settings.'];
        }

        $forecast = $this->care->forecastFor($context->household);

        return $forecast
            ? ['available' => true, 'next_24_hours' => $forecast->toArray(), 'source' => 'Open-Meteo']
            : ['available' => false, 'reason' => 'The weather service did not answer. Try again later.'];
    }
}
