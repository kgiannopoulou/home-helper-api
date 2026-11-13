<?php

/*
 * Day 84: the failures found or checked against the real Home Assistant
 * (ops/home-assistant/scripts/e2e.sh, docs/smart-home/testing.md), kept here
 * so CI catches them without one.
 */

use App\Enums\HouseholdRole;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Models\User;
use App\SmartHome\Automation\RuleValidator;
use App\SmartHome\Connections;
use App\SmartHome\Control;
use App\SmartHome\Discovery;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    Http::fake(['exp.host/*' => Http::response(['data' => []])]);
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    foreach (['Living room', 'Bathroom', 'Hallway'] as $room) {
        Room::factory()->for($this->home)->create(['name' => $room]);
    }
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->update(['imported' => true]);
    $this->control = app(Control::class);
});

function failDevice(string $entityId): SmartDevice
{
    return SmartDevice::where('entity_id', $entityId)->sole();
}

function failPush(object $test, string $entityId, string $state, string $at)
{
    return $test->withToken($test->bearer)->postJson('/api/smart-home/v1/states', ['entity_id' => $entityId, 'state' => $state, 'last_changed' => $at]);
}

it('fails an approved action cleanly when Home Assistant went off while it waited', function () {
    $row = $this->control->request(failDevice('lock.front_door_lock'), $this->member, 'unlock');
    $this->ha->failure = 'unreachable';

    $row = $this->control->approve($row, $this->owner);

    expect($row)->status->toBe('failed')->error->toContain('not answering')->finished_at->not->toBeNull()
        ->and($this->connection->fresh()->status)->toBe('unreachable');
});

it('does not pretend doors opened when Home Assistant restarts', function () {
    // A restart: everything goes unavailable, then comes back as it was, in any order
    $t = now();
    foreach (['binary_sensor.front_door', 'binary_sensor.bedroom_window', 'binary_sensor.hallway_motion'] as $i => $id) {
        failPush($this, $id, 'unavailable', $t->copy()->addSeconds(1)->toIso8601String())->assertAccepted();
    }
    foreach (array_reverse(['binary_sensor.front_door', 'binary_sensor.bedroom_window', 'binary_sensor.hallway_motion']) as $id) {
        failPush($this, $id, 'off', $t->copy()->addSeconds(30)->toIso8601String())->assertAccepted();
    }

    expect(SmartHomeEvent::whereIn('type', ['door.opened', 'window.opened', 'motion.detected'])->count())->toBe(0)
        ->and(failDevice('binary_sensor.front_door'))->state->toBe('off')->available->toBeTrue();
});

it('keeps the newest state when pushes arrive out of order (Home Assistant forwards in parallel)', function () {
    // The door opened at +2 s and closed at +5 s; the "closed" push arrives first
    $this->travel(1)->minutes();
    $t = now()->subSeconds(30);
    failPush($this, 'binary_sensor.front_door', 'off', $t->copy()->addSeconds(5)->toIso8601String());
    failPush($this, 'binary_sensor.front_door', 'on', $t->copy()->addSeconds(2)->toIso8601String());

    expect(failDevice('binary_sensor.front_door')->state)->toBe('off');
});

it('records a failed run when an automation fires while Home Assistant is off, and fires again later', function () {
    $plug = failDevice('switch.dehumidifier_plug');
    $data = app(RuleValidator::class)->validate($this->home, [
        'name' => 'Dehumidifier on', 'trigger' => ['type' => 'numeric', 'device_id' => failDevice('sensor.bathroom_humidity')->id, 'above' => 70],
        'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => $plug->id, 'action' => 'turn_on']],
    ]);
    $rule = new AutomationRule($data + ['created_by' => $this->owner->id]);
    $rule->household_id = $this->home->id;
    $rule->save();

    // The sensor reaches Home Helper, but the call back to switch the plug doesn't reach Home Assistant
    $this->ha->failure = 'unreachable';
    failPush($this, 'sensor.bathroom_humidity', '78', now()->addSecond()->toIso8601String())->assertAccepted();
    expect(AutomationRun::sole()->status)->toBe('failed');

    $this->ha->failure = null;
    $this->travel(1)->hours();
    failPush($this, 'sensor.bathroom_humidity', '60', now()->toIso8601String());
    failPush($this, 'sensor.bathroom_humidity', '79', now()->addSecond()->toIso8601String());
    expect(AutomationRun::latest('id')->first()->status)->toBe('done')
        ->and($this->ha->states['switch.dehumidifier_plug']['state'])->toBe('on');
});

it('turns a broken answer into an error, not a crash', function (string $failure, string $error) {
    $this->ha->failure = $failure;
    $row = $this->control->request(failDevice('light.living_room_lamp'), $this->member, 'turn_on');

    expect($row)->status->toBe('failed')->error->toContain($error);
})->with([
    'a router login page instead of Home Assistant' => ['not_json', 'JSON'],
    'Home Assistant itself failing' => ['server_error', '500'],
    'the token revoked in Home Assistant' => ['unauthorized', 'token'],
]);

it('marks the connection once and recovers once, however many calls fail', function () {
    $this->ha->failure = 'unreachable';
    foreach (range(1, 3) as $_) {
        try {
            app(Discovery::class)->sync($this->connection->fresh());
        } catch (Throwable) {
        }
    }
    $this->ha->failure = null;
    app(Discovery::class)->sync($this->connection->fresh());
    app(Discovery::class)->sync($this->connection->fresh());

    expect(SmartHomeEvent::where('type', 'hub.offline')->count())->toBe(1)
        ->and(SmartHomeEvent::where('type', 'hub.online')->count())->toBe(1)
        ->and($this->connection->fresh()->status)->toBe('connected');
});
