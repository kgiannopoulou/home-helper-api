<?php

use App\Enums\HouseholdRole;
use App\Enums\PlantAlertType;
use App\Enums\PlantCareType;
use App\Jobs\CheckPlantCare;
use App\Models\Device;
use App\Models\Household;
use App\Models\JobRun;
use App\Models\Plant;
use App\Models\PlantAlert;
use App\Models\User;
use App\Weather\WeatherForecast;
use App\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeWeatherProvider;

beforeEach(function () {
    // A Wednesday morning in Athens, away from the clock change
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Athens'));

    $this->home = Household::factory()->create(['latitude' => 37.98, 'longitude' => 23.73]);
    $this->me = User::factory()->create();
    $this->home->addMember($this->me, HouseholdRole::Owner);
    Device::forceCreate(['user_id' => $this->me->id, 'expo_token' => 'ExponentPushToken[me-phone]', 'platform' => 'android']);
    Http::fake([config('services.expo.push_url') => fn (Request $r) => Http::response(['data' => array_map(fn () => ['status' => 'ok'], $r->data())])]);

    // Mild by default: no weather rule fires
    $this->weather = new FakeWeatherProvider(forecast(min: 14, max: 24));
    app()->instance(WeatherProvider::class, $this->weather);
});

function forecast(float $min, float $max, float $rain = 0, float $gusts = 10): WeatherForecast
{
    return new WeatherForecast($min, $max, $rain, $gusts,
        minimumAt: CarbonImmutable::parse('2026-10-15 01:00', 'UTC'),
        maximumAt: CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));
}

/** A plant whose last watering was this many days ago */
function plantWatered(Household $home, int $daysAgo, array $attributes = [], ?Closure $state = null): Plant
{
    $plant = Plant::factory()->for($home)->when($state, $state)->create(['name' => 'Monstera', 'watering_interval_days' => 7, 'water_amount_ml' => 350, ...$attributes]);
    $home->plantCareLogs()->create(['plant_id' => $plant->id, 'type' => PlantCareType::Watered, 'amount_ml' => 350, 'performed_at' => now()->subDays($daysAgo)]);

    return $plant->refresh();
}

function checkPlants(Household $home): JobRun
{
    (new CheckPlantCare($home))->handle();

    return $home->jobRuns()->where('job', 'CheckPlantCare')->latest('id')->firstOrFail();
}

/** @return list<array<string, mixed>> every push sent to Expo */
function plantPushes(): array
{
    return Http::recorded()->flatMap(fn ($pair) => $pair[0]->data())->values()->all();
}

/** @return list<string> open alert types, sorted */
function openAlerts(?Plant $plant = null): array
{
    return PlantAlert::query()->open()->when($plant, fn ($q) => $q->where('plant_id', $plant->id))
        ->get()->map(fn (PlantAlert $a) => $a->type->value)->sort()->values()->all();
}

describe('watering reminders', function () {
    test('a plant due today raises one alert and one push', function () {
        plantWatered($this->home, 7);

        $run = checkPlants($this->home);

        $alert = PlantAlert::sole();
        expect($alert->type)->toBe(PlantAlertType::WateringDue)
            ->and($alert->severity->value)->toBe('info')
            ->and($alert->pushed_at)->not->toBeNull()
            ->and($alert->metadata)->toMatchArray(['amount_ml' => 350, 'overdue_days' => 0])
            ->and(plantPushes())->toHaveCount(1)
            ->and(plantPushes()[0])->toMatchArray(['title' => '🌿 Monstera needs water', 'body' => 'Give it about 350 ml today.'])
            ->and($run->summary['created'])->toEqual([['plant' => 'Monstera', 'type' => 'watering_due']]);
    });

    test('a plant not due yet raises nothing', function () {
        plantWatered($this->home, 2);

        checkPlants($this->home);

        expect(PlantAlert::count())->toBe(0)->and(plantPushes())->toBe([]);
    });

    test('the hourly check does not send the same reminder twice', function () {
        plantWatered($this->home, 7);

        checkPlants($this->home);
        $this->travel(1)->hours();
        checkPlants($this->home);
        $this->travel(1)->hours();
        checkPlants($this->home);

        expect(PlantAlert::count())->toBe(1)->and(plantPushes())->toHaveCount(1);
    });

    test('watering resolves the reminder and the next check stays quiet', function () {
        $plant = plantWatered($this->home, 7);
        checkPlants($this->home);

        Sanctum::actingAs($this->me);
        $this->postJson("/api/households/{$this->home->id}/plants/{$plant->id}/water")->assertCreated();

        expect(PlantAlert::sole()->resolved_at)->not->toBeNull();
        $this->travel(1)->hours();
        checkPlants($this->home);
        expect(PlantAlert::count())->toBe(1)->and(plantPushes())->toHaveCount(1);
    });

    test('two days late it becomes an overdue warning', function () {
        plantWatered($this->home, 10);

        checkPlants($this->home);

        $alert = PlantAlert::sole();
        expect($alert->type)->toBe(PlantAlertType::WateringOverdue)
            ->and($alert->severity->value)->toBe('warning')
            ->and($alert->title)->toBe('🌿 Monstera is 3 days overdue for water');
    });

    test('a due reminder turns into an overdue one, not a second open alert', function () {
        plantWatered($this->home, 7);
        checkPlants($this->home);

        $this->travel(2)->days();
        checkPlants($this->home);

        expect(openAlerts())->toBe(['watering_overdue'])
            ->and(PlantAlert::where('type', 'watering_due')->sole()->resolved_at)->not->toBeNull();
    });

    test('reminders wait for the reminder hours; weather warnings do not', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 03:00', 'Europe/Athens'));
        plantWatered($this->home, 7);
        Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 1, max: 9);

        checkPlants($this->home);
        expect(openAlerts())->toBe(['frost_warning']);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00', 'Europe/Athens'));
        checkPlants($this->home);
        expect(openAlerts())->toBe(['frost_warning', 'watering_due']);
    });

    test('fertilizer is due on its own schedule; repotting is raised but not pushed', function () {
        plantWatered($this->home, 1, ['fertilize_every_days' => 30, 'repot_every_months' => 12]);
        $plant = Plant::sole();
        $this->home->plantCareLogs()->create(['plant_id' => $plant->id, 'type' => PlantCareType::Fertilized, 'performed_at' => now()->subDays(31)]);
        $this->home->plantCareLogs()->create(['plant_id' => $plant->id, 'type' => PlantCareType::Repotted, 'performed_at' => now()->subMonths(18)]);

        checkPlants($this->home);

        expect(openAlerts())->toBe(['fertilizing_due', 'repotting_due'])
            ->and(PlantAlert::where('type', 'fertilizing_due')->sole()->message)->toBe('Last fertilized 31 days ago.')
            ->and(PlantAlert::where('type', 'repotting_due')->sole()->message)->toBe('Last repotted 18 months ago.')
            ->and(PlantAlert::where('type', 'repotting_due')->sole()->pushed_at)->toBeNull()
            ->and(plantPushes())->toHaveCount(1)
            ->and(plantPushes()[0]['title'])->toBe('🌿 Monstera is due for fertilizer');
    });

    test('several new alerts go out as one push, most severe first', function () {
        plantWatered($this->home, 7);
        Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 6, max: 14);

        checkPlants($this->home);

        expect(plantPushes())->toHaveCount(1)
            ->and(plantPushes()[0]['title'])->toBe('⚠️ 2 plant reminders')
            ->and(plantPushes()[0]['body'])->toBe('Move Basil inside · Monstera needs water');
    });
});

describe('weather', function () {
    test('indoor plants ignore the weather', function () {
        Plant::factory()->for($this->home)->create(['minimum_temperature_c' => 12, 'frost_sensitive' => true]);
        $this->weather->forecast = forecast(min: -3, max: 4, rain: 40, gusts: 90);

        checkPlants($this->home);

        expect(PlantAlert::count())->toBe(0)
            // No outdoor plant, so the forecast isn't even fetched
            ->and($this->weather->calls)->toBe(0);
    });

    test('an outdoor plant below its minimum should come inside', function () {
        $basil = Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 7, max: 15);

        checkPlants($this->home);

        $alert = PlantAlert::sole();
        expect($alert->type)->toBe(PlantAlertType::MoveInside)
            ->and($alert->plant_id)->toBe($basil->id)
            ->and($alert->title)->toBe('⚠️ Move Basil inside')
            // 01:00 UTC is 04:00 in Athens
            ->and($alert->message)->toBe("It may fall to 7°C around 04:00. Basil's minimum is 10°C.")
            ->and($alert->metadata)->toMatchArray(['minimum_temperature_c' => 7, 'plant_minimum_c' => 10]);
    });

    test('frost is urgent for frost-sensitive plants', function () {
        Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 1, max: 9);

        checkPlants($this->home);

        $alert = PlantAlert::sole();
        expect($alert->type)->toBe(PlantAlertType::FrostWarning)
            ->and($alert->severity->value)->toBe('urgent')
            ->and(plantPushes()[0]['title'])->toBe('❄️ Frost risk: move Basil inside');
    });

    test('heat above the maximum: move to shade', function () {
        Plant::factory()->for($this->home)->outdoor()->create(['maximum_temperature_c' => 32]);
        $this->weather->forecast = forecast(min: 24, max: 38);

        checkPlants($this->home);

        expect(PlantAlert::sole()->type)->toBe(PlantAlertType::MoveToShade)
            ->and(PlantAlert::sole()->message)->toBe("It may reach 38°C around 15:00. Basil's maximum is 32°C.");
    });

    test('rain postpones watering outdoors, not indoors', function () {
        $balcony = plantWatered($this->home, 7, ['name' => 'Basil', 'water_amount_ml' => 150], fn ($f) => $f->outdoor());
        $indoor = plantWatered($this->home, 7);
        $this->weather->forecast = forecast(min: 14, max: 20, rain: 12);

        checkPlants($this->home);

        expect(openAlerts($balcony))->toBe(['watering_postponed'])
            ->and(PlantAlert::where('plant_id', $balcony->id)->sole()->message)
            ->toBe("12 mm of rain is expected in the next 24 hours, so Basil probably doesn't need its usual 150 ml.")
            ->and(openAlerts($indoor))->toBe(['watering_due']);
    });

    test('heavy rain and strong wind only warn the plants that mind them', function () {
        $sensitive = Plant::factory()->for($this->home)->outdoor()->create(['name' => 'Lemon tree', 'rain_sensitive' => true, 'wind_sensitive' => true]);
        $tough = Plant::factory()->for($this->home)->outdoor()->create(['name' => 'Rosemary']);
        $this->weather->forecast = forecast(min: 14, max: 20, rain: 25, gusts: 65);

        checkPlants($this->home);

        expect(openAlerts($sensitive))->toBe(['heavy_rain', 'strong_wind'])
            ->and(openAlerts($tough))->toBe([]);
    });

    test('a weather alert resolves when the forecast clears, and is not raised again within the cooldown', function () {
        Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 6, max: 14);
        checkPlants($this->home);
        expect(openAlerts())->toBe(['move_inside']);

        // The cached forecast is replaced half an hour later
        $this->travel(1)->hours();
        $this->weather->forecast = forecast(min: 13, max: 22);
        checkPlants($this->home);
        expect(openAlerts())->toBe([]);

        $this->travel(1)->hours();
        $this->weather->forecast = forecast(min: 6, max: 14);
        checkPlants($this->home);
        expect(openAlerts())->toBe([]);

        $this->travel(12)->hours();
        checkPlants($this->home);
        expect(openAlerts())->toBe(['move_inside'])->and(plantPushes())->toHaveCount(2);
    });

    test('moving a plant inside resolves its weather alert', function () {
        $basil = Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 6, max: 14);
        checkPlants($this->home);

        Sanctum::actingAs($this->me);
        $this->postJson("/api/households/{$this->home->id}/plants/{$basil->id}/move", ['location_type' => 'indoor'])->assertCreated();
        $this->travel(1)->hours();
        checkPlants($this->home);

        expect(openAlerts())->toBe([])->and($basil->fresh()->location_type->value)->toBe('indoor');
    });

    test('one forecast per household, cached across plants and runs', function () {
        Plant::factory()->count(3)->for($this->home)->outdoor()->create();

        checkPlants($this->home);
        $this->travel(10)->minutes();
        checkPlants($this->home);

        expect($this->weather->calls)->toBe(1);
    });

    test('without a location there is no weather, but care reminders still go out', function () {
        $this->home->update(['latitude' => null, 'longitude' => null]);
        plantWatered($this->home, 7, ['name' => 'Basil'], fn ($f) => $f->outdoor());

        $run = checkPlants($this->home);

        expect($this->weather->calls)->toBe(0)
            ->and($run->summary['forecast'])->toBeNull()
            ->and(openAlerts())->toBe(['watering_due']);
    });

    test('the weather service being down does not fail the job or clear weather alerts', function () {
        Plant::factory()->for($this->home)->outdoor()->create();
        $this->weather->forecast = forecast(min: 6, max: 14);
        checkPlants($this->home);

        $this->travel(1)->hours();
        cache()->flush();
        $this->weather->down = true;
        $run = checkPlants($this->home);

        expect($run->status->value)->toBe('succeeded')
            ->and(openAlerts())->toBe(['move_inside']);
    });
});

test('deleting a plant resolves its alerts', function () {
    $plant = plantWatered($this->home, 7);
    checkPlants($this->home);

    $plant->delete();

    expect(openAlerts())->toBe([]);
});
