<?php

namespace App\Plants;

use App\Enums\AlertSeverity;
use App\Enums\PlantAlertType;
use App\Models\Household;
use App\Models\Plant;
use App\Support\HomeTime;
use App\Weather\WeatherForecast;
use App\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The plant care rules: what each plant needs today, how much water, and what
 * the weather changes. Controllers and CheckPlantCare ask; nothing here sends
 * or stores anything.
 */
class PlantCareService
{
    public function __construct(private WeatherProvider $weather) {}

    /**
     * The next 24 hours at the household, fetched once and cached: a home with
     * 40 plants still makes one request. Null without a location, or when the
     * provider fails (then the weather rules are skipped, the rest still runs).
     */
    public function forecastFor(Household $household): ?WeatherForecast
    {
        if (! $household->hasLocation()) {
            return null;
        }

        $key = sprintf('weather:%.2f,%.2f', $household->latitude, $household->longitude);
        try {
            return Cache::remember(
                $key,
                now()->addMinutes(self::config('forecast_cache_minutes')),
                fn () => $this->weather->forecast($household->latitude, $household->longitude),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The plant's usual amount, more on a hot day and less on a cool one when
     * it's outside. Rounded to 10 ml; null when the plant has no amount set.
     */
    public function waterAmount(Plant $plant, ?WeatherForecast $forecast): ?int
    {
        if ($plant->water_amount_ml === null) {
            return null;
        }
        $factor = 1.0;
        if ($forecast && $plant->exposed()) {
            $factor = match (true) {
                $forecast->maximumTemperatureC >= self::config('hot_c') => 1.15,
                $forecast->maximumTemperatureC <= self::config('cool_c') => 0.85,
                default => 1.0,
            };
        }

        return max(10, (int) round($plant->water_amount_ml * $factor / 10) * 10);
    }

    /** Enough rain is coming to water an outdoor plant for us */
    public function rainWaters(Plant $plant, ?WeatherForecast $forecast): bool
    {
        return $forecast !== null && $plant->exposed()
            && $forecast->precipitationMm >= self::config('rain_skips_watering_mm');
    }

    /**
     * Everything the plant needs now: its care schedule, then the weather.
     *
     * @return list<PlantWarning>
     */
    public function warnings(Plant $plant, ?WeatherForecast $forecast): array
    {
        return [...$this->careWarnings($plant, $forecast), ...$this->weatherWarnings($plant, $forecast)];
    }

    /**
     * Watering, fertilizer and repotting that are due today.
     *
     * @return list<PlantWarning>
     */
    public function careWarnings(Plant $plant, ?WeatherForecast $forecast): array
    {
        $today = HomeTime::today();
        $tomorrow = $today->addDay();
        $name = $plant->label();
        $warnings = [];

        if ($plant->wateringDue($tomorrow)) {
            $amount = $this->waterAmount($plant, $forecast);
            $overdue = $this->daysOverdue($plant->next_watering_at, $today);
            $give = $amount ? "Give it about {$amount} ml" : 'Give it a drink';
            $metadata = ['amount_ml' => $amount, 'due_at' => $plant->next_watering_at->toIso8601String(), 'overdue_days' => $overdue];

            $warnings[] = match (true) {
                $this->rainWaters($plant, $forecast) => new PlantWarning(PlantAlertType::WateringPostponed,
                    '🌧️ Watering postponed',
                    sprintf('%s mm of rain is expected in the next 24 hours, so %s probably doesn\'t need %s.',
                        self::number($forecast->precipitationMm), $name, $amount ? "its usual {$amount} ml" : 'watering'),
                    [...$metadata, 'precipitation_mm' => $forecast->precipitationMm]),
                $overdue >= self::config('overdue_after_days') => new PlantWarning(PlantAlertType::WateringOverdue,
                    "🌿 {$name} is {$overdue} days overdue for water",
                    "{$give} as soon as you can.",
                    $metadata),
                default => new PlantWarning(PlantAlertType::WateringDue,
                    "🌿 {$name} needs water",
                    "{$give} today.",
                    $metadata),
            };
        }

        if ($plant->fertilizingDue($tomorrow)) {
            $warnings[] = new PlantWarning(PlantAlertType::FertilizingDue,
                "🌿 {$name} is due for fertilizer",
                $plant->last_fertilized_at
                    ? sprintf('Last fertilized %s.', self::ago($plant->last_fertilized_at, $today))
                    : 'Not fertilized since it was added.',
                ['due_at' => $plant->next_fertilizing_at->toIso8601String()]);
        }

        if ($plant->repottingDue($tomorrow)) {
            $warnings[] = new PlantWarning(PlantAlertType::RepottingDue,
                "🪴 {$name} may be ready for repotting",
                $plant->last_repotted_at
                    ? sprintf('Last repotted %s.', self::ago($plant->last_repotted_at, $today))
                    : 'Not repotted since it was added.',
                ['due_at' => $plant->next_repotting_at->toIso8601String()]);
        }

        return $warnings;
    }

    /**
     * What the next 24 hours mean for a plant out in the weather. Indoor and
     * greenhouse plants never get these.
     *
     * @return list<PlantWarning>
     */
    public function weatherWarnings(Plant $plant, ?WeatherForecast $forecast): array
    {
        if ($forecast === null || ! $plant->exposed()) {
            return [];
        }
        $name = $plant->label();
        $low = self::number($forecast->minimumTemperatureC);
        $high = self::number($forecast->maximumTemperatureC);
        $lowAt = self::around($forecast->minimumAt);
        $highAt = self::around($forecast->maximumAt);
        $warnings = [];

        if ($plant->frost_sensitive && $forecast->minimumTemperatureC <= self::config('frost_c')) {
            $warnings[] = new PlantWarning(PlantAlertType::FrostWarning,
                "❄️ Frost risk: move {$name} inside",
                "It may fall to {$low}°C{$lowAt}. Frost can kill {$name} overnight.",
                ['minimum_temperature_c' => $forecast->minimumTemperatureC, 'minimum_at' => $forecast->minimumAt?->toIso8601String()]);
        } elseif ($plant->minimum_temperature_c !== null && $forecast->minimumTemperatureC < $plant->minimum_temperature_c) {
            $warnings[] = new PlantWarning(PlantAlertType::MoveInside,
                "⚠️ Move {$name} inside",
                sprintf("It may fall to {$low}°C{$lowAt}. %s's minimum is %s°C.", $name, self::number($plant->minimum_temperature_c)),
                ['minimum_temperature_c' => $forecast->minimumTemperatureC, 'plant_minimum_c' => $plant->minimum_temperature_c]);
        }

        if ($plant->maximum_temperature_c !== null && $forecast->maximumTemperatureC > $plant->maximum_temperature_c) {
            $warnings[] = new PlantWarning(PlantAlertType::MoveToShade,
                "☀️ Move {$name} to shade",
                sprintf("It may reach {$high}°C{$highAt}. %s's maximum is %s°C.", $name, self::number($plant->maximum_temperature_c)),
                ['maximum_temperature_c' => $forecast->maximumTemperatureC, 'plant_maximum_c' => $plant->maximum_temperature_c]);
        }

        if ($plant->rain_sensitive && $forecast->precipitationMm >= self::config('heavy_rain_mm')) {
            $warnings[] = new PlantWarning(PlantAlertType::HeavyRain,
                "🌧️ Heavy rain: cover {$name}",
                sprintf('%s mm of rain is expected in the next 24 hours. Move %s under cover so its roots don\'t sit in water.',
                    self::number($forecast->precipitationMm), $name),
                ['precipitation_mm' => $forecast->precipitationMm]);
        }

        if ($plant->wind_sensitive && $forecast->maxWindGustKmh >= self::config('strong_wind_kmh')) {
            $warnings[] = new PlantWarning(PlantAlertType::StrongWind,
                '💨 Strong wind expected',
                sprintf('Gusts up to %s km/h. Move %s to a sheltered spot.', self::number($forecast->maxWindGustKmh), $name),
                ['max_wind_gust_kmh' => $forecast->maxWindGustKmh]);
        }

        return $warnings;
    }

    /**
     * "What needs attention today?" for GET …/plant-care/today: watering (with
     * today's amount), other care that's due, weather warnings and the next week.
     *
     * @return array<string, mixed>
     */
    public function today(Household $household): array
    {
        $today = HomeTime::today();
        $tomorrow = $today->addDay();
        $weekEnd = $today->addDays(8);
        $plants = $household->plants()->orderBy('name')->orderBy('id')->get();
        $forecast = $plants->contains(fn (Plant $p) => $p->exposed()) ? $this->forecastFor($household) : null;

        $watering = $care = $weather = $upcoming = [];
        foreach ($plants as $plant) {
            $row = ['plant_id' => $plant->id, 'name' => $plant->label()];

            if ($plant->wateringDue($tomorrow)) {
                $watering[] = [...$row,
                    'amount_ml' => $this->waterAmount($plant, $forecast),
                    'due_at' => $plant->next_watering_at->toIso8601String(),
                    'overdue_days' => $this->daysOverdue($plant->next_watering_at, $today),
                    'postponed_for_rain' => $this->rainWaters($plant, $forecast),
                ];
            }
            foreach ($this->careWarnings($plant, $forecast) as $w) {
                if (! in_array($w->type, [PlantAlertType::WateringDue, PlantAlertType::WateringOverdue, PlantAlertType::WateringPostponed], true)) {
                    $care[] = [...$row, 'type' => $w->type->value, 'title' => $w->title, 'message' => $w->message];
                }
            }
            foreach ($this->weatherWarnings($plant, $forecast) as $w) {
                $weather[] = [...$row, 'type' => $w->type->value, 'severity' => $w->severity()->value, 'title' => $w->title, 'message' => $w->message];
            }

            foreach (['water' => $plant->next_watering_at, 'fertilize' => $plant->next_fertilizing_at, 'repot' => $plant->next_repotting_at] as $type => $at) {
                if ($at !== null && $at >= $tomorrow && $at < $weekEnd) {
                    $upcoming[] = [...$row, 'type' => $type, 'due_on' => $at->setTimezone(HomeTime::zone())->toDateString()];
                }
            }
        }

        usort($upcoming, fn ($a, $b) => [$a['due_on'], $a['name']] <=> [$b['due_on'], $b['name']]);
        usort($weather, fn ($a, $b) => self::severityRank($b) <=> self::severityRank($a));

        return [
            'date' => $today->toDateString(),
            'forecast' => $forecast?->toArray(),
            'watering' => $watering,
            'care' => $care,
            'weather_warnings' => $weather,
            'upcoming' => $upcoming,
        ];
    }

    /**
     * Who needs what while you're away: each plant's waterings and fertilizer
     * between two days at home, as if done on schedule. Care already overdue
     * falls on the first day.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(Household $household, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $zone = HomeTime::zone();

        return $household->plants()->orderBy('name')->orderBy('id')->get()
            ->map(function (Plant $plant) use ($from, $to, $zone) {
                $care = collect([
                    ...$this->repeat('water', $plant->next_watering_at, $plant->watering_interval_days, $from, $to, $zone),
                    ...$this->repeat('fertilize', $plant->next_fertilizing_at, $plant->fertilize_every_days, $from, $to, $zone),
                ]);
                if ($plant->next_repotting_at && $plant->next_repotting_at->setTimezone($zone)->startOfDay() <= $to) {
                    $care->push(['type' => 'repot', 'on' => max($from, $plant->next_repotting_at->setTimezone($zone)->startOfDay())->toDateString()]);
                }

                return [
                    'plant_id' => $plant->id,
                    'name' => $plant->label(),
                    'location_type' => $plant->location_type->value,
                    'amount_ml' => $plant->water_amount_ml,
                    // Outdoor plants also depend on the weather, which no plan can know
                    'check_weather' => $plant->exposed(),
                    'care' => $care->sortBy('on')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, on: string}>
     */
    private function repeat(string $type, ?CarbonImmutable $next, ?int $everyDays, CarbonImmutable $from, CarbonImmutable $to, string $zone): array
    {
        if ($next === null || ! $everyDays) {
            return [];
        }
        $day = max($from, $next->setTimezone($zone)->startOfDay());
        $out = [];
        while ($day <= $to) {
            $out[] = ['type' => $type, 'on' => $day->toDateString()];
            $day = $day->addDays($everyDays);
        }

        return $out;
    }

    /** Whole days since the day it was due, at home; 0 when due today */
    private function daysOverdue(CarbonImmutable $due, CarbonImmutable $today): int
    {
        $dueDay = $due->setTimezone(HomeTime::zone())->startOfDay();

        return $dueDay < $today ? (int) round($dueDay->diffInDays($today)) : 0;
    }

    /** @param array{severity: string} $row */
    private static function severityRank(array $row): int
    {
        return AlertSeverity::from($row['severity'])->rank();
    }

    /** "30 days ago", "18 months ago" */
    private static function ago(CarbonImmutable $at, CarbonImmutable $today): string
    {
        $day = $at->setTimezone(HomeTime::zone())->startOfDay();
        $days = (int) round($day->diffInDays($today));
        if ($days >= 60) {
            return sprintf('%d months ago', (int) floor($day->diffInMonths($today)));
        }

        return $days === 1 ? 'yesterday' : "{$days} days ago";
    }

    /** " around 04:00" at home, or nothing */
    private static function around(?CarbonImmutable $at): string
    {
        return $at ? ' around '.$at->setTimezone(HomeTime::zone())->format('H:i') : '';
    }

    /** 7 for 7.0, 6.5 for 6.5 */
    private static function number(float $value): string
    {
        return (string) round($value, 1);
    }

    public static function config(string $key): mixed
    {
        return config("homehelper.plants.$key");
    }
}
