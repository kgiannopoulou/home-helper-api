<?php

namespace App\Jobs;

use App\Enums\PlantAlertType;
use App\Models\Household;
use App\Models\Plant;
use App\Models\PlantAlert;
use App\Plants\PlantCareService;
use App\Plants\PlantWarning;
use App\Push\ExpoPush;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;

/**
 * Hourly: asks PlantCareService what each plant needs, keeps plant_alerts in
 * step with it, and pushes what's new.
 *
 * - An open alert of the same type for the same plant means it was already
 *   sent, so nothing is sent again until it is resolved.
 * - An alert whose reason has gone (watered, the frost passed) is resolved.
 * - Watering, fertilizer and repotting reminders wait for the reminder hours at
 *   home; weather warnings go out whenever they appear.
 * - A weather warning isn't raised again within the cooldown of the last one,
 *   so a plant that was moved inside doesn't get the same warning next hour.
 *
 * The job coordinates; the service decides; ExpoPush communicates.
 */
class CheckPlantCare extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $plants = $household->plants()->orderBy('name')->get();
        if ($plants->isEmpty()) {
            return ['plants' => 0];
        }

        $care = app(PlantCareService::class);
        $now = CarbonImmutable::now();
        $forecast = $plants->contains(fn (Plant $p) => $p->exposed()) ? $care->forecastFor($household) : null;
        [$from, $until] = PlantCareService::config('reminder_hours');
        $hour = $now->setTimezone(HomeTime::zone())->hour;
        $reminderHours = $hour >= $from && $hour < $until;

        $open = $household->plantAlerts()->open()->get()->keyBy(fn (PlantAlert $a) => "{$a->plant_id}|{$a->type->value}");
        $recentWeather = $household->plantAlerts()
            ->whereIn('type', array_filter(PlantAlertType::cases(), fn ($t) => $t->weather()))
            ->where('triggered_at', '>=', $now->subHours(PlantCareService::config('weather_cooldown_hours')))
            ->get()
            ->keyBy(fn (PlantAlert $a) => "{$a->plant_id}|{$a->type->value}");

        $created = collect();
        $resolved = 0;
        foreach ($plants as $plant) {
            $warnings = collect($care->warnings($plant, $forecast))->keyBy(fn (PlantWarning $w) => $w->type->value);

            foreach ($warnings as $type => $warning) {
                $key = "{$plant->id}|{$type}";
                if ($open->has($key)
                    || ($warning->type->weather() && $recentWeather->has($key))
                    || (! $warning->type->weather() && ! $reminderHours)) {
                    continue;
                }
                $created->push($household->plantAlerts()->create([
                    'plant_id' => $plant->id,
                    'type' => $warning->type,
                    'severity' => $warning->severity(),
                    'title' => $warning->title,
                    'message' => $warning->message,
                    'metadata' => $warning->metadata ?: null,
                    'triggered_at' => $now,
                ]));
            }

            // No forecast (no location, provider down): the weather is unknown, not gone.
            // A plant moved indoors is out of the weather either way.
            $stale = $open->filter(fn (PlantAlert $a) => $a->plant_id === $plant->id
                && ! $warnings->has($a->type->value)
                && ($forecast !== null || ! $a->type->weather() || ! $plant->exposed()));
            foreach ($stale as $alert) {
                $alert->update(['resolved_at' => $now]);
                $resolved++;
            }
        }

        $summary = [
            'plants' => $plants->count(),
            'forecast' => $forecast?->toArray(),
            'created' => $created->map(fn (PlantAlert $a) => ['plant' => $plants->firstWhere('id', $a->plant_id)->name, 'type' => $a->type->value])->all(),
            'resolved' => $resolved,
        ];

        $toPush = $created->filter(fn (PlantAlert $a) => $a->type->pushes())
            ->sortByDesc(fn (PlantAlert $a) => $a->severity->rank())
            ->values();
        if ($toPush->isEmpty()) {
            return $summary;
        }

        [$title, $body] = $toPush->count() === 1
            ? [$toPush[0]->title, $toPush[0]->message]
            : [
                sprintf('%s %d plant reminders', $toPush[0]->severity->rank() >= 2 ? '⚠️' : '🪴', $toPush->count()),
                $toPush->map(fn (PlantAlert $a) => self::withoutEmoji($a->title))->join(' · '),
            ];
        $push = app(ExpoPush::class)->toUsers($household->users, $title, $body, ['url' => '/plants']);
        if ($push['sent'] > 0) {
            PlantAlert::query()->whereKey($toPush->pluck('id')->all())->update(['pushed_at' => $now]);
        }

        return [...$summary, 'push' => $push];
    }

    /** "🌿 Monstera needs water" → "Monstera needs water", for a list in one push */
    private static function withoutEmoji(string $title): string
    {
        return preg_replace('/^[^\p{L}\p{N}]+/u', '', $title);
    }
}
