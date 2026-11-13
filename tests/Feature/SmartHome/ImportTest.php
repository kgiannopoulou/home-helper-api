<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\User;
use App\SmartHome\Capabilities;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\Import;
use App\Web\CurrentHousehold;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    [$this->connection] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    app(Discovery::class)->sync($this->connection);
    $this->import = app(Import::class);
});

function device(string $entityId): SmartDevice
{
    return SmartDevice::where('entity_id', $entityId)->sole();
}

it('offers lights, switches, locks and the sensors it understands, not sun times', function () {
    $candidates = $this->import->candidates($this->home)->pluck('entity_id');

    expect($candidates)->toHaveCount(17)
        ->toContain('light.living_room_lamp', 'switch.dehumidifier_plug', 'sensor.bedroom_temperature', 'binary_sensor.hallway_motion', 'lock.front_door_lock')
        ->not->toContain('sensor.sun_next_dawn');
});

it('imports the chosen devices only', function () {
    $count = $this->import->import($this->home, $this->owner, [device('light.living_room_lamp')->id, device('sensor.bathroom_humidity')->id]);

    expect($count)->toBe(2)
        ->and(SmartDevice::active()->pluck('entity_id')->all())->toEqualCanonicalizing(['light.living_room_lamp', 'sensor.bathroom_humidity'])
        ->and($this->import->candidates($this->home))->toHaveCount(15);
});

it('refuses kinds it cannot use, other households\' devices and members', function () {
    expect(fn () => $this->import->import($this->home, $this->owner, [device('sensor.sun_next_dawn')->id]))->toThrow(ValidationException::class);

    $other = Household::factory()->create();
    $foreign = new SmartDevice(['connection_id' => $this->connection->id, 'entity_id' => 'light.theirs', 'domain' => 'light', 'kind' => 'light', 'name' => 'Theirs']);
    $foreign->household_id = $other->id;
    $foreign->save();
    expect(fn () => $this->import->import($this->home, $this->owner, [$foreign->id]))->toThrow(ValidationException::class);

    expect(fn () => $this->import->import($this->home, $this->member, [device('light.living_room_lamp')->id]))->toThrow(ValidationException::class, 'owner');
});

it('reads capabilities from Home Assistant, not from names', function () {
    expect(Capabilities::of(device('light.living_room_lamp')))->toMatchArray(['switchable' => true, 'dimmable' => true])
        ->and(Capabilities::of(device('light.kitchen_ceiling')))->toMatchArray(['switchable' => true, 'dimmable' => false])
        ->and(Capabilities::of(device('lock.front_door_lock')))->toMatchArray(['lockable' => true, 'switchable' => false])
        ->and(Capabilities::of(device('sensor.bedroom_temperature'))['reading'])->toBeTrue()
        ->and(Capabilities::of(device('binary_sensor.front_door'))['contact'])->toBeTrue();
});

it('describes states in words', function () {
    $this->ha->set('light.living_room_lamp', 'on', ['brightness' => 128]);
    $this->ha->set('binary_sensor.bedroom_window', 'on');
    app(Discovery::class)->sync($this->connection);

    expect(Capabilities::stateLabel(device('light.living_room_lamp')))->toBe('On · 50%')
        ->and(Capabilities::stateLabel(device('binary_sensor.bedroom_window')))->toBe('Open')
        ->and(Capabilities::stateLabel(device('sensor.bedroom_temperature')))->toBe('19.5 °C')
        ->and(Capabilities::stateLabel(device('lock.front_door_lock')))->toBe('Locked');
});

describe('the page', function () {
    beforeEach(fn () => $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]));

    it('imports from the page, then lists the device by room', function () {
        $living = Room::factory()->for($this->home)->create(['name' => 'Living room']);
        app(Discovery::class)->sync($this->connection);

        $this->post('/smart-home/devices/import', ['devices' => [device('light.living_room_lamp')->id]])->assertRedirect('/smart-home');
        $this->get('/smart-home')->assertInertia(fn (Page $p) => $p
            ->has('devices', 1)
            ->where('devices.0.name', 'Living room lamp')
            ->where('devices.0.room', 'Living room')
            ->where('devices.0.capabilities.dimmable', true)
            ->has('candidates', 16));
        expect(device('light.living_room_lamp')->room_id)->toBe($living->id);
    });

    it('moves a device to another room and stops using it', function () {
        $bedroom = Room::factory()->for($this->home)->create(['name' => 'Spare room']);
        $lamp = device('light.living_room_lamp');
        $this->import->import($this->home, $this->owner, [$lamp->id]);

        $this->patch("/smart-home/devices/{$lamp->id}", ['room_id' => $bedroom->id])->assertRedirect();
        expect($lamp->fresh()->room_id)->toBe($bedroom->id);

        $this->patch("/smart-home/devices/{$lamp->id}", ['room_id' => Room::factory()->create()->id])->assertSessionHasErrors('room_id');

        $this->delete("/smart-home/devices/{$lamp->id}")->assertRedirect();
        expect($lamp->fresh()->imported)->toBeFalse();
    });

    it('syncs on demand and shows Home Assistant errors', function () {
        $this->ha->set('switch.dehumidifier_plug', 'on');
        $this->post('/smart-home/sync')->assertRedirect();
        expect(device('switch.dehumidifier_plug')->state)->toBe('on');

        $this->ha->failure = 'unreachable';
        $this->post('/smart-home/sync')->assertSessionHasErrors('sync');
    });

    it('gives members the devices but not the import list', function () {
        $this->import->import($this->home, $this->owner, [device('light.living_room_lamp')->id]);
        $this->actingAs($this->member)->get('/smart-home')->assertInertia(fn (Page $p) => $p->has('devices', 1)->has('candidates', 0));
        $this->post('/smart-home/devices/import', ['devices' => [device('switch.hallway_light')->id]])->assertSessionHasErrors('devices');
    });
});
