<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeAction;
use App\Models\User;
use App\SmartHome\Connections;
use App\SmartHome\Control;
use App\SmartHome\Discovery;
use App\SmartHome\Events\DeviceStateChanged;
use App\Web\CurrentHousehold;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
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
    SmartDevice::query()->whereIn('kind', ['light', 'switch', 'temperature', 'door'])->update(['imported' => true]);
    $this->control = app(Control::class);
});

function dev(string $entityId): SmartDevice
{
    return SmartDevice::where('entity_id', $entityId)->sole();
}

it('turns a light on through Home Assistant and records who did it', function () {
    Event::fake([DeviceStateChanged::class]);
    $action = $this->control->request(dev('light.living_room_lamp'), $this->member, 'turn_on');

    expect($action)->status->toBe('done')->requested_by->toBe($this->member->id)->source->toBe('dashboard')
        ->and($this->ha->calls)->toBe([['domain' => 'light', 'service' => 'turn_on', 'data' => ['entity_id' => 'light.living_room_lamp']]])
        // The reply updated the stored state straight away
        ->and(dev('light.living_room_lamp')->state)->toBe('on');
    Event::assertDispatched(DeviceStateChanged::class, fn ($e) => $e->source === 'control' && $e->to === 'on');
});

it('dims a dimmable light, in percent', function () {
    $this->control->request(dev('light.living_room_lamp'), $this->member, 'set_brightness', ['brightness' => 40]);

    expect($this->ha->calls[0])->toMatchArray(['service' => 'turn_on', 'data' => ['entity_id' => 'light.living_room_lamp', 'brightness_pct' => 40]])
        ->and(dev('light.living_room_lamp')->brightnessPercent())->toBe(40);
});

it('switches a plug', function () {
    $this->control->request(dev('switch.dehumidifier_plug'), $this->member, 'turn_on');
    expect(dev('switch.dehumidifier_plug')->state)->toBe('on');
    $this->control->request(dev('switch.dehumidifier_plug'), $this->member, 'toggle');
    expect(dev('switch.dehumidifier_plug')->state)->toBe('off');
});

it('refuses what a device cannot do, before calling Home Assistant', function (string $entity, string $action, array $params, string $message) {
    expect(fn () => $this->control->request(dev($entity), $this->member, $action, $params))->toThrow(ValidationException::class, $message);
    expect($this->ha->calls)->toBe([])->and(SmartHomeAction::count())->toBe(0);
})->with([
    'dim an on/off light' => ['light.kitchen_ceiling', 'set_brightness', ['brightness' => 50], "can't do that"],
    'dim a plug' => ['switch.dehumidifier_plug', 'set_brightness', ['brightness' => 50], "can't do that"],
    'switch a sensor' => ['sensor.bedroom_temperature', 'turn_on', [], "can't do that"],
    'brightness 0' => ['light.living_room_lamp', 'set_brightness', ['brightness' => 0], '1 to 100'],
    'not imported' => ['lock.front_door_lock', 'unlock', [], 'not one of the devices'],
]);

it('refuses a device that is not answering', function () {
    $this->ha->set('light.living_room_lamp', 'unavailable');
    app(Discovery::class)->sync($this->connection);

    expect(fn () => $this->control->request(dev('light.living_room_lamp'), $this->member, 'turn_on'))->toThrow(ValidationException::class, 'isn\'t answering');
});

it('records a failed call and marks the connection', function () {
    $this->ha->failure = 'unreachable';
    $action = $this->control->request(dev('light.living_room_lamp'), $this->member, 'turn_on');

    expect($action)->status->toBe('failed')->error->toContain('not answering')->finished_at->not->toBeNull()
        ->and($this->connection->fresh()->status)->toBe('unreachable')
        ->and(dev('light.living_room_lamp')->state)->toBe('off');
});

it('lets rules act without a person', function () {
    $action = $this->control->request(dev('switch.dehumidifier_plug'), null, 'turn_on', [], 'rule');
    expect($action)->status->toBe('done')->requested_by->toBeNull()->source->toBe('rule');
});

it('controls from the dashboard and shows errors there', function () {
    $this->actingAs($this->member)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
    $lamp = dev('light.living_room_lamp');

    $this->post("/smart-home/devices/{$lamp->id}/actions", ['action' => 'turn_on'])->assertRedirect('/smart-home')->assertSessionHasNoErrors();
    expect($lamp->fresh()->state)->toBe('on');

    $this->post("/smart-home/devices/{$lamp->id}/actions", ['action' => 'explode'])->assertSessionHasErrors('action');

    $this->ha->failure = 'server_error';
    $this->post("/smart-home/devices/{$lamp->id}/actions", ['action' => 'turn_off'])->assertSessionHasErrors('control');
});

it('does not let anyone else switch the household\'s devices', function () {
    $stranger = User::factory()->create();
    $lamp = dev('light.living_room_lamp');

    expect(fn () => $this->control->request($lamp, $stranger, 'turn_on'))->toThrow(ValidationException::class);

    // The web route only sees the current household's devices
    $other = Household::factory()->create();
    $other->addMember($stranger, HouseholdRole::Owner);
    $this->actingAs($stranger)->withSession([CurrentHousehold::SESSION_KEY => $other->id])
        ->post("/smart-home/devices/{$lamp->id}/actions", ['action' => 'turn_on'])->assertNotFound();
    expect($this->ha->calls)->toBe([]);
});

describe('the phone API', function () {
    it('lists the imported devices and the summary', function () {
        Sanctum::actingAs($this->member);
        $this->getJson("/api/households/{$this->home->id}/smart-home")->assertOk()
            ->assertJsonCount(11, 'devices')
            ->assertJsonPath('summary.lights', 4)
            ->assertJsonMissingPath('devices.0.token');
    });

    it('controls a device and answers with its new state', function () {
        Sanctum::actingAs($this->member);
        $lamp = dev('light.living_room_lamp');

        $this->postJson("/api/households/{$this->home->id}/smart-home/devices/{$lamp->id}/actions", ['action' => 'set_brightness', 'brightness' => 75])
            ->assertOk()->assertJsonPath('action.status', 'done')->assertJsonPath('device.brightness', 75)->assertJsonPath('device.on', true);
        expect(SmartHomeAction::sole()->source)->toBe('api');

        $this->ha->failure = 'unreachable';
        $this->postJson("/api/households/{$this->home->id}/smart-home/devices/{$lamp->id}/actions", ['action' => 'turn_off'])
            ->assertStatus(502)->assertJsonPath('action.status', 'failed');
    });

    it('404s another household\'s device', function () {
        $other = Household::factory()->create();
        $other->addMember($this->member);
        Sanctum::actingAs($this->member);
        $lamp = dev('light.living_room_lamp');
        $this->postJson("/api/households/{$other->id}/smart-home/devices/{$lamp->id}/actions", ['action' => 'turn_on'])->assertNotFound();
    });
});
