<?php

use App\Enums\HouseholdRole;
use App\Enums\PlantAlertType;
use App\Models\Household;
use App\Models\Plant;
use App\Models\PlantCareLog;
use App\Models\Room;
use App\Models\User;
use App\Weather\OpenMeteoWeatherProvider;
use App\Weather\WeatherForecast;
use App\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeWeatherProvider;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Athens'));

    $this->me = User::factory()->create();
    $this->home = Household::factory()->create(['latitude' => 37.98, 'longitude' => 23.73]);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->stranger = User::factory()->create();
    $this->away = Household::factory()->create();
    $this->away->addMember($this->stranger, HouseholdRole::Owner);

    $this->weather = new FakeWeatherProvider(new WeatherForecast(14, 24));
    app()->instance(WeatherProvider::class, $this->weather);

    $this->monstera = Plant::factory()->for($this->home)->create(['name' => 'Monstera', 'watering_interval_days' => 7, 'water_amount_ml' => 350]);
    $this->url = "/api/households/{$this->home->id}/plants/{$this->monstera->id}";
    Sanctum::actingAs($this->me);
});

describe('care actions', function () {
    test('a new plant is first due one interval after it was added', function () {
        expect($this->monstera->next_watering_at->toIso8601String())->toBe('2026-10-21T07:00:00+00:00')
            ->and($this->monstera->last_watered_at)->toBeNull();
    });

    test('watering logs the usual amount and moves the next watering', function () {
        $response = $this->postJson("{$this->url}/water")
            ->assertCreated()
            ->assertJsonPath('data.type', 'watered')
            ->assertJsonPath('data.amount_ml', 350)
            ->assertJsonPath('data.user_id', $this->me->id)
            ->assertJsonPath('plant.last_watered_at', '2026-10-14T07:00:00+00:00')
            ->assertJsonPath('plant.next_watering_at', '2026-10-21T07:00:00+00:00');

        expect(PlantCareLog::sole()->id)->toBe($response->json('data.id'));
    });

    test('the time a phone sends is kept, in UTC, and the amount can differ', function () {
        $this->postJson("{$this->url}/water", ['amount_ml' => 300, 'performed_at' => '2026-10-13T18:30:00+03:00'])
            ->assertCreated()
            ->assertJsonPath('data.amount_ml', 300)
            ->assertJsonPath('data.performed_at', '2026-10-13T15:30:00+00:00')
            ->assertJsonPath('plant.next_watering_at', '2026-10-20T15:30:00+00:00');
    });

    test('care in the future is refused', function () {
        $this->postJson("{$this->url}/water", ['performed_at' => '2026-10-20T10:00:00+03:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('performed_at');
    });

    test('an older watering logged later doesn\'t move the dates back', function () {
        $this->postJson("{$this->url}/water")->assertCreated();
        $this->postJson("{$this->url}/water", ['performed_at' => '2026-10-10T09:00:00+03:00'])->assertCreated();

        expect($this->monstera->fresh()->last_watered_at->toIso8601String())->toBe('2026-10-14T07:00:00+00:00');
    });

    test('deleting a mistaken watering puts the dates back', function () {
        $this->postJson("{$this->url}/water", ['performed_at' => '2026-10-10T09:00:00+03:00'])->assertCreated();
        $wrong = $this->postJson("{$this->url}/water")->json('data.id');

        $this->deleteJson("/api/households/{$this->home->id}/plant-care-logs/$wrong")->assertNoContent();

        expect($this->monstera->fresh()->last_watered_at->toIso8601String())->toBe('2026-10-10T06:00:00+00:00')
            ->and($this->monstera->fresh()->next_watering_at->toIso8601String())->toBe('2026-10-17T06:00:00+00:00');
    });

    test('changing the interval moves the next watering at once', function () {
        $this->postJson("{$this->url}/water")->assertCreated();

        $this->patchJson($this->url, ['watering_interval_days' => 10])
            ->assertOk()
            ->assertJsonPath('data.next_watering_at', '2026-10-24T07:00:00+00:00');
    });

    test('care dates come from the logs, never from the body', function () {
        $this->patchJson($this->url, ['last_watered_at' => '2026-01-01T00:00:00Z', 'next_watering_at' => '2030-01-01T00:00:00Z'])->assertOk();

        expect($this->monstera->fresh()->last_watered_at)->toBeNull()
            ->and($this->monstera->fresh()->next_watering_at->toIso8601String())->toBe('2026-10-21T07:00:00+00:00');
    });

    test('watering resolves the open watering reminder', function () {
        $alert = $this->home->plantAlerts()->create(['plant_id' => $this->monstera->id, 'type' => PlantAlertType::WateringDue,
            'severity' => 'info', 'title' => 'Monstera needs water', 'message' => '…', 'triggered_at' => now()]);

        $this->postJson("{$this->url}/water")->assertCreated();

        expect($alert->fresh()->resolved_at)->not->toBeNull();
    });

    test('fertilizing and repotting have their own schedules', function () {
        $this->patchJson($this->url, ['fertilize_every_days' => 30, 'repot_every_months' => 18])
            ->assertJsonPath('data.next_fertilizing_at', '2026-11-13T07:00:00+00:00')
            ->assertJsonPath('data.next_repotting_at', '2028-04-14T07:00:00+00:00');

        $this->postJson("{$this->url}/fertilize", ['notes' => 'Half strength'])
            ->assertCreated()
            ->assertJsonPath('data.amount_ml', null)
            ->assertJsonPath('plant.last_fertilized_at', '2026-10-14T07:00:00+00:00');
        $this->postJson("{$this->url}/repot")->assertCreated()->assertJsonPath('plant.next_repotting_at', '2028-04-14T07:00:00+00:00');
    });

    test('moving needs a place, and changes where the plant is', function () {
        $this->postJson("{$this->url}/move", [])->assertUnprocessable()->assertJsonValidationErrors('location_type');

        $room = Room::factory()->for($this->home)->create(['name' => 'Bedroom']);
        $this->postJson("{$this->url}/move", ['location_type' => 'balcony', 'room_id' => $room->id, 'notes' => 'More sun'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'moved')
            ->assertJsonPath('plant.location_type', 'balcony')
            ->assertJsonPath('plant.room_id', $room->id);
    });

    test('health notes and care make one timeline, newest first', function () {
        $this->postJson("{$this->url}/water", ['performed_at' => '2026-10-05T09:00:00+03:00']);
        $this->postJson("{$this->url}/inspect", ['notes' => 'Yellow lower leaves', 'performed_at' => '2026-10-12T09:00:00+03:00']);
        $this->postJson("{$this->url}/water");

        $this->getJson("{$this->url}/care-history")
            ->assertOk()
            ->assertJsonPath('data.*.type', ['watered', 'inspected', 'watered'])
            ->assertJsonPath('data.1.notes', 'Yellow lower leaves');

        $this->getJson("{$this->url}/care-history?type=inspected")->assertJsonCount(1, 'data');
    });

    test('another household\'s plant is not found, and their room is refused', function () {
        $theirs = Plant::factory()->for($this->away)->create();
        $theirRoom = Room::factory()->for($this->away)->create();

        $this->postJson("/api/households/{$this->home->id}/plants/{$theirs->id}/water")->assertNotFound();
        $this->getJson("/api/households/{$this->home->id}/plants/{$theirs->id}/care-history")->assertNotFound();
        $this->postJson("/api/households/{$this->away->id}/plants/{$theirs->id}/water")->assertForbidden();
        $this->patchJson($this->url, ['room_id' => $theirRoom->id])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->postJson("/api/households/{$this->home->id}/plant-care-logs", [
            'plant_id' => $theirs->id, 'type' => 'watered', 'performed_at' => '2026-10-14T08:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('plant_id');

        expect(PlantCareLog::count())->toBe(0);
    });
});

describe('validation', function () {
    test('rejects impossible plants', function (array $change, string $field) {
        $this->postJson("/api/households/{$this->home->id}/plants", [
            'name' => 'Aloe', 'location_type' => 'indoor', 'watering_interval_days' => 14, ...$change,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'no water' => [['water_amount_ml' => 0], 'water_amount_ml'],
        'too much water' => [['water_amount_ml' => 100001], 'water_amount_ml'],
        'never watered' => [['watering_interval_days' => 0], 'watering_interval_days'],
        'colder than space' => [['minimum_temperature_c' => -80], 'minimum_temperature_c'],
        'maximum below minimum' => [['minimum_temperature_c' => 15, 'maximum_temperature_c' => 10], 'maximum_temperature_c'],
        'unknown light' => [['light_requirement' => 'disco'], 'light_requirement'],
    ]);

    test('a PATCH that crosses the stored limits is caught by the CHECK constraint', function () {
        $this->patchJson($this->url, ['maximum_temperature_c' => 5])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'constraint_violation');
    });
});

describe('alerts', function () {
    beforeEach(function () {
        $make = fn (Plant $p, PlantAlertType $type, array $extra = []) => $p->household->plantAlerts()->create([
            'plant_id' => $p->id, 'type' => $type, 'severity' => $type->severity(), 'title' => $type->value, 'message' => '…', 'triggered_at' => now(), ...$extra,
        ]);
        $this->open = $make($this->monstera, PlantAlertType::WateringDue);
        $this->old = $make($this->monstera, PlantAlertType::FertilizingDue, ['triggered_at' => now()->subDays(3), 'resolved_at' => now()->subDays(2)]);
        $this->theirs = $make(Plant::factory()->for($this->away)->create(), PlantAlertType::WateringDue);
    });

    test('lists open alerts by default, history on request', function () {
        $alerts = "/api/households/{$this->home->id}/plant-alerts";

        $this->getJson($alerts)->assertOk()->assertJsonPath('data.*.id', [$this->open->id]);
        $this->getJson("$alerts?status=resolved")->assertJsonPath('data.*.id', [$this->old->id]);
        $this->getJson("$alerts?status=all")->assertJsonCount(2, 'data');
        $this->getJson("$alerts?type=fertilizing_due&status=all")->assertJsonPath('data.*.id', [$this->old->id]);
    });

    test('a member can dismiss an alert; another household\'s is not found', function () {
        $this->postJson("/api/households/{$this->home->id}/plant-alerts/{$this->open->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.metadata.dismissed_by', $this->me->id);
        expect($this->open->fresh()->resolved_at)->not->toBeNull();

        $this->postJson("/api/households/{$this->home->id}/plant-alerts/{$this->theirs->id}/resolve")->assertNotFound();
        expect($this->theirs->fresh()->resolved_at)->toBeNull();
    });

    test('alerts of a deleted plant are resolved and hidden', function () {
        $this->deleteJson($this->url)->assertNoContent();

        $this->getJson("/api/households/{$this->home->id}/plant-alerts?status=all")->assertJsonCount(0, 'data');
        expect($this->open->fresh()->resolved_at)->not->toBeNull();
    });
});

describe('today and the away plan', function () {
    test('what needs attention today, with the weather', function () {
        // Monstera: due today. Basil on the balcony: due, on a hot day, and too hot for it.
        PlantCareLog::factory()->for($this->home)->create(['plant_id' => $this->monstera->id, 'performed_at' => now()->subDays(8)]);
        $basil = Plant::factory()->for($this->home)->outdoor()->create(['watering_interval_days' => 2, 'water_amount_ml' => 150, 'maximum_temperature_c' => 30]);
        PlantCareLog::factory()->for($this->home)->create(['plant_id' => $basil->id, 'performed_at' => now()->subDays(2)]);
        $aloe = Plant::factory()->for($this->home)->create(['name' => 'Aloe', 'watering_interval_days' => 14, 'fertilize_every_days' => 60]);
        PlantCareLog::factory()->for($this->home)->create(['plant_id' => $aloe->id, 'performed_at' => now()->subDays(11)]);
        $this->weather->forecast = new WeatherForecast(22, 34, maximumAt: CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));

        $today = $this->getJson("/api/households/{$this->home->id}/plant-care/today")->assertOk()->json('data');

        expect($today['date'])->toBe('2026-10-14')
            ->and($today['forecast']['maximum_temperature_c'])->toEqual(34)
            ->and(collect($today['watering'])->map(fn ($w) => [$w['name'], $w['amount_ml'], $w['overdue_days']])->all())
            // Hot day outside: 15% more for the basil; the Monstera is a day late
            ->toBe([['Basil', 170, 0], ['Monstera', 350, 1]])
            ->and($today['weather_warnings'])->toHaveCount(1)
            ->and($today['weather_warnings'][0])->toMatchArray(['name' => 'Basil', 'type' => 'move_to_shade', 'severity' => 'warning'])
            ->and($today['upcoming'])->toBe([['plant_id' => $aloe->id, 'name' => 'Aloe', 'type' => 'water', 'due_on' => '2026-10-17']]);
    });

    test('the weather is skipped when the household has no location', function () {
        $this->home->update(['latitude' => null, 'longitude' => null]);
        Plant::factory()->for($this->home)->outdoor()->create();

        $today = $this->getJson("/api/households/{$this->home->id}/plant-care/today")->assertOk()->json('data');

        expect($today['forecast'])->toBeNull()->and($today['weather_warnings'])->toBe([])->and($this->weather->calls)->toBe(0);
    });

    test('a plan for while you are away', function () {
        PlantCareLog::factory()->for($this->home)->create(['plant_id' => $this->monstera->id, 'performed_at' => now()->subDays(4)]);
        $basil = Plant::factory()->for($this->home)->outdoor()->create(['watering_interval_days' => 3]);
        PlantCareLog::factory()->for($this->home)->create(['plant_id' => $basil->id, 'performed_at' => now()->subDays(5)]);
        Plant::factory()->for($this->home)->create(['name' => 'Snake plant', 'watering_interval_days' => 30]);

        $plan = $this->getJson("/api/households/{$this->home->id}/plant-care/plan?from=2026-10-15&to=2026-10-22")->assertOk()->json('data');

        $byName = collect($plan['plants'])->keyBy('name');
        expect($plan['from'])->toBe('2026-10-15')
            ->and($byName['Monstera']['care'])->toBe([['type' => 'water', 'on' => '2026-10-17']])
            // Overdue on the first day, then every 3 days
            ->and(collect($byName['Basil']['care'])->pluck('on')->all())->toBe(['2026-10-15', '2026-10-18', '2026-10-21'])
            ->and($byName['Basil']['check_weather'])->toBeTrue()
            ->and($byName['Snake plant']['care'])->toBe([]);
    });

    test('a plan needs a sensible range', function (string $query) {
        $this->getJson("/api/households/{$this->home->id}/plant-care/plan?$query")->assertUnprocessable()->assertJsonValidationErrors('to');
    })->with([
        'backwards' => ['from=2026-10-20&to=2026-10-15'],
        'too long' => ['from=2026-10-15&to=2027-03-01'],
        'missing' => ['from=2026-10-15'],
    ]);
});

describe('household location', function () {
    test('the owner sets where the home is', function () {
        $this->patchJson("/api/households/{$this->home->id}", ['latitude' => 40.64, 'longitude' => 22.94])
            ->assertOk()
            ->assertJsonPath('data.latitude', 40.64)
            ->assertJsonPath('data.longitude', 22.94)
            ->assertJsonPath('data.role', 'owner');

        $this->patchJson("/api/households/{$this->home->id}", ['latitude' => null, 'longitude' => null])->assertOk();
        expect($this->home->fresh()->hasLocation())->toBeFalse();
    });

    test('both coordinates, in range, and only the owner', function () {
        $this->patchJson("/api/households/{$this->home->id}", ['latitude' => 40.64])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->patchJson("/api/households/{$this->home->id}", ['latitude' => 91, 'longitude' => 0])->assertUnprocessable()->assertJsonValidationErrors('latitude');

        $member = User::factory()->create();
        $this->home->addMember($member);
        Sanctum::actingAs($member);
        $this->patchJson("/api/households/{$this->home->id}", ['latitude' => 1, 'longitude' => 1])->assertForbidden();
    });
});

describe('sync', function () {
    test('waterings from two offline phones both arrive; the latest sets the dates', function () {
        $sync = fn (string $id, string $at, string $updated) => $this->postJson("/api/households/{$this->home->id}/sync", ['since' => null, 'changes' => [
            'plant_care_logs' => [['id' => $id, 'plant_id' => $this->monstera->id, 'type' => 'watered', 'amount_ml' => 350, 'performed_at' => $at, 'updated_at' => $updated]],
        ]])->assertOk()->assertJsonPath('rejected', []);

        // Phone B (10:05) syncs before phone A (10:00)
        $sync('01JPHONEB00000000000000001', '2026-10-14T07:05:00Z', '2026-10-14T07:05:00.000Z');
        $sync('01JPHONEA00000000000000001', '2026-10-14T07:00:00Z', '2026-10-14T07:00:00.000Z');

        $plant = $this->monstera->fresh();
        expect(PlantCareLog::count())->toBe(2)
            ->and($plant->last_watered_at->toIso8601String())->toBe('2026-10-14T07:05:00+00:00')
            ->and($plant->next_watering_at->toIso8601String())->toBe('2026-10-21T07:05:00+00:00')
            // Not an edit: the plant's own last-write-wins clock didn't move
            ->and($plant->updated_at->equalTo($this->monstera->updated_at))->toBeTrue();
    });

    test('a plant from a phone keeps the server\'s care dates', function () {
        $this->postJson("/api/households/{$this->home->id}/sync", ['since' => null, 'changes' => ['plants' => [[
            'id' => '01JPLANT000000000000000001', 'name' => 'Pothos', 'location_type' => 'indoor', 'watering_interval_days' => 5,
            'next_watering_at' => '2030-01-01T00:00:00Z', 'updated_at' => '2026-10-14T06:00:00.000Z',
        ]]]])->assertOk()->assertJsonPath('rejected', []);

        expect(Plant::findOrFail('01JPLANT000000000000000001')->next_watering_at->toIso8601String())->toBe('2026-10-19T07:00:00+00:00');
    });
});

test('Open-Meteo hours become one forecast', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response(['hourly' => [
        'time' => ['2026-10-14T07:00', '2026-10-14T13:00', '2026-10-15T01:00'],
        'temperature_2m' => [12.4, 21.6, 6.2],
        'precipitation' => [0, 2.5, 1.1],
        'wind_gusts_10m' => [20.1, 48.3, null],
    ]])]);

    $forecast = (new OpenMeteoWeatherProvider)->forecast(37.98, 23.73);

    expect($forecast->minimumTemperatureC)->toBe(6.2)
        ->and($forecast->minimumAt->toIso8601String())->toBe('2026-10-15T01:00:00+00:00')
        ->and($forecast->maximumTemperatureC)->toBe(21.6)
        ->and($forecast->maximumAt->toIso8601String())->toBe('2026-10-14T13:00:00+00:00')
        ->and($forecast->precipitationMm)->toBe(3.6)
        ->and($forecast->maxWindGustKmh)->toBe(48.3);
    Http::assertSent(fn (Request $r) => $r['latitude'] == 37.98 && $r['forecast_hours'] == 24 && str_contains($r['hourly'], 'temperature_2m'));
});
