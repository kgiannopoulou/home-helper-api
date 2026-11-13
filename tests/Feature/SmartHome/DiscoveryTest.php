<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\Events\DeviceStateChanged;
use App\SmartHome\HomeAssistantError;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    $this->discovery = app(Discovery::class);
});

it('discovers the devices Home Helper understands, and nothing else', function () {
    Event::fake([DeviceStateChanged::class]);
    $result = $this->discovery->sync($this->connection);

    expect($result)->toMatchArray(['found' => 18, 'new' => 18, 'removed' => 0, 'changed' => 0]);
    $ids = SmartDevice::pluck('entity_id');
    expect($ids)->toContain('light.living_room_lamp', 'binary_sensor.front_door', 'lock.front_door_lock', 'sensor.bathroom_humidity')
        ->not->toContain('sun.sun', 'person.owner');

    $lamp = SmartDevice::where('entity_id', 'light.living_room_lamp')->sole();
    expect($lamp)->kind->toBe('light')->name->toBe('Living room lamp')->area->toBe('Living Room')
        ->state->toBe('off')->imported->toBeFalse()->household_id->toBe($this->home->id);
    expect(SmartDevice::where('entity_id', 'binary_sensor.bedroom_window')->value('kind'))->toBe('window')
        ->and(SmartDevice::where('entity_id', 'sensor.bathroom_humidity')->sole())->kind->toBe('humidity')->unit->toBe('%')
        ->and(SmartDevice::where('entity_id', 'sensor.sun_next_dawn')->value('kind'))->toBe('sensor');

    // A device that just appeared is not a change: nothing to notify or automate
    Event::assertNotDispatched(DeviceStateChanged::class);
    expect($this->connection->fresh()->last_synced_at)->not->toBeNull();
});

it('matches Home Assistant areas to the household\'s rooms by name', function () {
    $living = Room::factory()->for($this->home)->create(['name' => 'Living room']);
    $bath = Room::factory()->for($this->home)->create(['name' => 'bathroom']);
    Room::factory()->create(['name' => 'Kitchen']); // another household's

    $this->discovery->sync($this->connection);

    expect(SmartDevice::where('entity_id', 'light.living_room_lamp')->value('room_id'))->toBe($living->id)
        ->and(SmartDevice::where('entity_id', 'switch.dehumidifier_plug')->value('room_id'))->toBe($bath->id)
        ->and(SmartDevice::where('entity_id', 'light.kitchen_ceiling')->value('room_id'))->toBeNull();
});

it('announces a state change found by a sync, once', function () {
    $this->discovery->sync($this->connection);
    Event::fake([DeviceStateChanged::class]);

    $this->ha->set('binary_sensor.front_door', 'on');
    expect($this->discovery->sync($this->connection)['changed'])->toBe(1)
        ->and($this->discovery->sync($this->connection)['changed'])->toBe(0);

    Event::assertDispatchedTimes(DeviceStateChanged::class, 1);
    Event::assertDispatched(DeviceStateChanged::class, fn ($e) => $e->device->entity_id === 'binary_sensor.front_door' && $e->from === 'off' && $e->to === 'on' && $e->source === 'sync');
});

it('marks removed devices, and brings them back', function () {
    $this->discovery->sync($this->connection);
    $this->ha->remove('switch.coffee_machine_plug');

    expect($this->discovery->sync($this->connection)['removed'])->toBe(1);
    $plug = SmartDevice::where('entity_id', 'switch.coffee_machine_plug')->sole();
    expect($plug->removed_at)->not->toBeNull()->and($plug->available)->toBeFalse();

    $this->ha->seed();
    $this->discovery->sync($this->connection);
    expect($plug->fresh()->removed_at)->toBeNull();
});

it('marks unavailable devices', function () {
    $this->discovery->sync($this->connection);
    $this->ha->set('light.living_room_lamp', 'unavailable');
    $this->discovery->sync($this->connection);

    expect(SmartDevice::where('entity_id', 'light.living_room_lamp')->value('available'))->toBeFalse();
});

it('records a failed sync on the connection and keeps the devices', function () {
    $this->discovery->sync($this->connection);
    $this->ha->failure = 'unreachable';

    expect(fn () => $this->discovery->sync($this->connection))->toThrow(HomeAssistantError::class);
    expect($this->connection->fresh()->status)->toBe('unreachable')
        ->and(SmartDevice::whereNull('removed_at')->count())->toBe(18);
});

it('runs from the scheduler for every connection', function () {
    $this->artisan('smart-home:sync')->expectsOutputToContain('18 devices, 18 new')->assertSuccessful();

    // A Pi that's off doesn't fail the scheduled run
    $this->ha->failure = 'unreachable';
    $this->artisan('smart-home:sync')->assertSuccessful();
});

describe('pushed state changes', function () {
    beforeEach(fn () => $this->discovery->sync($this->connection));

    function push(object $test, array $body)
    {
        return $test->withToken($test->bearer)->postJson('/api/smart-home/v1/states', $body);
    }

    it('applies a pushed state and announces it', function () {
        Event::fake([DeviceStateChanged::class]);
        push($this, ['entity_id' => 'binary_sensor.front_door', 'state' => 'on', 'attributes' => ['device_class' => 'door', 'friendly_name' => 'Front door'], 'last_changed' => now()->addSecond()->toIso8601String()])
            ->assertAccepted()->assertJsonPath('known', true);

        expect(SmartDevice::where('entity_id', 'binary_sensor.front_door')->value('state'))->toBe('on');
        Event::assertDispatched(DeviceStateChanged::class, fn ($e) => $e->to === 'on' && $e->source === 'webhook');
    });

    it('never lets an older state overwrite a newer one', function () {
        push($this, ['entity_id' => 'binary_sensor.front_door', 'state' => 'on', 'last_changed' => now()->addSecond()->toIso8601String()]);
        push($this, ['entity_id' => 'binary_sensor.front_door', 'state' => 'off', 'last_changed' => now()->subMinute()->toIso8601String()]);

        expect(SmartDevice::where('entity_id', 'binary_sensor.front_door')->value('state'))->toBe('on');
    });

    it('waits for the next sync for an entity it does not know', function () {
        push($this, ['entity_id' => 'light.new_bulb', 'state' => 'on'])->assertAccepted()->assertJsonPath('known', false);
        expect(SmartDevice::where('entity_id', 'light.new_bulb')->exists())->toBeFalse();
    });

    it('keeps only small attributes', function () {
        push($this, ['entity_id' => 'sensor.bathroom_humidity', 'state' => '61', 'attributes' => ['unit_of_measurement' => '%', 'forecast' => str_repeat('x', 2000)], 'last_changed' => now()->addSecond()->toIso8601String()]);
        $attributes = SmartDevice::where('entity_id', 'sensor.bathroom_humidity')->value('ha_attributes');
        expect($attributes)->toHaveKey('unit_of_measurement')->not->toHaveKey('forecast');
    });
});
