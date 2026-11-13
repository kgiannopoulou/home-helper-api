<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartDeviceReading;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\SmartHome\Comfort;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\Import;
use App\SmartHome\Readings;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    Room::factory()->for($this->home)->create(['name' => 'Bathroom']);
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    $sensors = SmartDevice::whereIn('kind', ['temperature', 'humidity'])->pluck('id')->all();
    app(Import::class)->import($this->home, $this->owner, $sensors);
});

function sensor(string $entityId): SmartDevice
{
    return SmartDevice::where('entity_id', $entityId)->sole();
}

it('takes a first reading of each sensor when it is imported', function () {
    expect(SmartDeviceReading::count())->toBe(6)
        ->and(SmartDeviceReading::where('device_id', sensor('sensor.bathroom_humidity')->id)->value('value'))->toBe(55.0);
});

it('records pushed changes, at most one row a minute per sensor', function () {
    $this->travel(2)->minutes();
    foreach (['61', '64', '66'] as $i => $value) {
        $this->withToken($this->bearer)->postJson('/api/smart-home/v1/states', [
            'entity_id' => 'sensor.bathroom_humidity', 'state' => $value,
            'last_changed' => now()->addSeconds($i + 1)->toIso8601String(),
        ])->assertAccepted();
    }

    $rows = SmartDeviceReading::where('device_id', sensor('sensor.bathroom_humidity')->id)->orderBy('recorded_at')->pluck('value')->all();
    // The first reading (55), then one row for the minute, holding its latest value
    expect($rows)->toBe([55.0, 66.0]);
});

it('ignores sensors that are not imported and values that are not numbers', function () {
    $readings = app(Readings::class);
    $lamp = SmartDevice::where('entity_id', 'light.living_room_lamp')->sole();
    expect($readings->record($lamp))->toBeNull();

    $bath = sensor('sensor.bathroom_humidity');
    $bath->forceFill(['state' => 'unavailable'])->save();
    expect($readings->record($bath))->toBeNull();
});

it('tops up steady sensors so the charts have no gaps', function () {
    $readings = app(Readings::class);
    expect($readings->topUp($this->home->id))->toBe(0);

    $this->travel(16)->minutes();
    expect($readings->topUp($this->home->id))->toBe(6);
});

it('averages history into buckets per sensor', function () {
    $bath = sensor('sensor.bathroom_humidity');
    SmartDeviceReading::query()->delete();
    $start = CarbonImmutable::now()->subHour()->startOfHour();
    foreach ([[0, 60], [5, 70], [20, 50]] as [$minutes, $value]) {
        $r = new SmartDeviceReading(['device_id' => $bath->id, 'value' => $value, 'recorded_at' => $start->addMinutes($minutes)]);
        $r->household_id = $this->home->id;
        $r->save();
    }

    $history = app(Readings::class)->history(new EloquentCollection([$bath]));
    expect($history)->toHaveCount(2)
        ->and($history[0][$bath->id])->toBe(65.0)
        ->and($history[1][$bath->id])->toBe(50.0);
});

it('assesses comfort with the usual indoor thresholds', function (?float $t, ?float $h, string $status) {
    expect(Comfort::assess($t, $h)[0])->toBe($status);
})->with([
    [21.0, 50.0, 'comfortable'],
    [21.0, 66.0, 'humid'],
    [21.0, 72.0, 'very_humid'],
    [16.5, 50.0, 'cold'],
    [27.0, 50.0, 'warm'],
    [21.0, 25.0, 'dry'],
    // Humidity matters more: a cold, wet room is a mould problem first
    [16.0, 75.0, 'very_humid'],
    [null, null, 'comfortable'],
]);

it('computes the dew point', function () {
    expect(Comfort::dewPoint(21.0, 70.0))->toBe(15.3)
        ->and(Comfort::dewPoint(20.0, 50.0))->toBe(9.3);
});

it('shows comfort per room and the history on the page', function () {
    $this->ha->set('sensor.bathroom_humidity', '72');
    app(Discovery::class)->sync($this->connection);

    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id])
        ->get('/smart-home')->assertInertia(fn (Page $p) => $p
        ->where('comfort.0.room', 'Bathroom')
        ->where('comfort.0.status', 'very_humid')
        ->where('comfort.0.humidity', 72)
        ->has('climate.sensors', 6)
        ->has('climate.history', 1));
});

it('prunes old readings', function () {
    $this->travel(31)->days();
    $this->artisan('model:prune', ['--model' => [SmartDeviceReading::class]])->assertSuccessful();
    expect(SmartDeviceReading::count())->toBe(0);
});
