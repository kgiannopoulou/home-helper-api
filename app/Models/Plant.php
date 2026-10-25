<?php

namespace App\Models;

use App\Enums\LightRequirement;
use App\Enums\PlantAlertType;
use App\Enums\PlantCareType;
use App\Enums\PlantLocation;
use App\Models\Concerns\BelongsToHousehold;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Database\Factories\PlantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A plant in the home, with its care schedules.
 *
 * last_* and next_* are not fillable: they follow from the care logs and the
 * intervals (refreshCareDates() and the saving hook), never from a phone.
 *
 * @property PlantLocation $location_type
 * @property CarbonImmutable|null $next_watering_at
 * @property CarbonImmutable|null $next_fertilizing_at
 * @property CarbonImmutable|null $next_repotting_at
 */
#[Fillable([
    'room_id', 'name', 'species', 'nickname', 'location_type', 'light_requirement',
    'watering_interval_days', 'water_amount_ml', 'fertilize_every_days', 'repot_every_months',
    'minimum_temperature_c', 'maximum_temperature_c', 'frost_sensitive', 'rain_sensitive', 'wind_sensitive', 'notes',
])]
class Plant extends Model
{
    /** @use HasFactory<PlantFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'location_type' => PlantLocation::class,
            'light_requirement' => LightRequirement::class,
            'watering_interval_days' => 'integer',
            'water_amount_ml' => 'integer',
            'fertilize_every_days' => 'integer',
            'repot_every_months' => 'integer',
            'minimum_temperature_c' => 'float',
            'maximum_temperature_c' => 'float',
            'frost_sensitive' => 'boolean',
            'rain_sensitive' => 'boolean',
            'wind_sensitive' => 'boolean',
            'last_watered_at' => 'datetime',
            'next_watering_at' => 'datetime',
            'last_fertilized_at' => 'datetime',
            'next_fertilizing_at' => 'datetime',
            'last_repotted_at' => 'datetime',
            'next_repotting_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every save, wherever it comes from (API, sync, a care log): a changed
        // interval moves the next date at once
        static::saving(fn (Plant $plant) => $plant->scheduleNextCare());

        // A deleted plant needs nothing any more
        static::deleted(fn (Plant $plant) => $plant->alerts()->whereNull('resolved_at')->update(['resolved_at' => now()]));
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<PlantCareLog, $this>
     */
    public function careLogs(): HasMany
    {
        return $this->hasMany(PlantCareLog::class);
    }

    /**
     * @return HasMany<PlantAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(PlantAlert::class);
    }

    /** Out in the weather */
    public function exposed(): bool
    {
        return $this->location_type->exposed();
    }

    /** The name to show: "Basil (Bob)" when it has a nickname */
    public function label(): string
    {
        return $this->nickname ? "{$this->name} ({$this->nickname})" : $this->name;
    }

    /**
     * Sets last_watered_at, last_fertilized_at and last_repotted_at from the live
     * care logs, then the next dates. The latest event wins, whichever phone sent
     * it and in whatever order they arrived. Doesn't touch updated_at: this isn't
     * an edit, and last write wins in sync compares updated_at.
     */
    public function refreshCareDates(): void
    {
        $latest = $this->careLogs()
            ->whereIn('type', [PlantCareType::Watered, PlantCareType::Fertilized, PlantCareType::Repotted])
            ->groupBy('type')
            ->selectRaw('type, max(performed_at) as at')
            ->pluck('at', 'type');

        foreach ([PlantCareType::Watered, PlantCareType::Fertilized, PlantCareType::Repotted] as $type) {
            $this->{$type->lastColumn()} = $latest[$type->value] ?? null;
        }
        // The saving hook does this too, but not when model events are off (seeders)
        $this->scheduleNextCare();

        $this->timestamps = false;
        $this->save();
        $this->timestamps = true;

        $this->resolveCareAlerts();
    }

    /**
     * Closes reminders the plant no longer needs: watered, so not due today.
     * Weather alerts are closed by the hourly check, which has the forecast.
     */
    public function resolveCareAlerts(): void
    {
        $tomorrow = self::startOfTomorrow();
        $done = array_filter([
            ...($this->next_watering_at >= $tomorrow ? [PlantAlertType::WateringDue, PlantAlertType::WateringOverdue, PlantAlertType::WateringPostponed] : []),
            $this->next_fertilizing_at === null || $this->next_fertilizing_at >= $tomorrow ? PlantAlertType::FertilizingDue : null,
            $this->next_repotting_at === null || $this->next_repotting_at >= $tomorrow ? PlantAlertType::RepottingDue : null,
        ]);

        if ($done) {
            $this->alerts()->whereNull('resolved_at')->whereIn('type', $done)->update(['resolved_at' => now()]);
        }
    }

    /** Due before today ends at home */
    public function wateringDue(?CarbonImmutable $tomorrow = null): bool
    {
        return $this->next_watering_at !== null && $this->next_watering_at < ($tomorrow ?? self::startOfTomorrow());
    }

    public function fertilizingDue(?CarbonImmutable $tomorrow = null): bool
    {
        return $this->next_fertilizing_at !== null && $this->next_fertilizing_at < ($tomorrow ?? self::startOfTomorrow());
    }

    public function repottingDue(?CarbonImmutable $tomorrow = null): bool
    {
        return $this->next_repotting_at !== null && $this->next_repotting_at < ($tomorrow ?? self::startOfTomorrow());
    }

    /**
     * Next care = last time + interval. Never done: counted from when the plant
     * was added, so a new plant isn't due on its first day.
     */
    private function scheduleNextCare(): void
    {
        $since = fn (?CarbonImmutable $last) => CarbonImmutable::parse($last ?? $this->created_at ?? now());

        $this->next_watering_at = $since($this->last_watered_at)->addDays($this->watering_interval_days);
        $this->next_fertilizing_at = $this->fertilize_every_days
            ? $since($this->last_fertilized_at)->addDays($this->fertilize_every_days)
            : null;
        $this->next_repotting_at = $this->repot_every_months
            ? $since($this->last_repotted_at)->addMonthsNoOverflow($this->repot_every_months)
            : null;
    }

    private static function startOfTomorrow(): CarbonImmutable
    {
        return HomeTime::today()->addDay();
    }
}
