<?php

use App\Enums\HouseholdRole;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\SmartHome\Automation\RuleEngine;
use App\SmartHome\Automation\Templates;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 21:00', 'Europe/Athens'));
    $this->ha = FakeHomeAssistant::install();
    Http::fake(['exp.host/*' => Http::response(['data' => []])]);
    $this->home = Household::factory()->create(['latitude' => 37.98, 'longitude' => 23.73]);
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    foreach (['Living room', 'Kitchen', 'Bathroom', 'Bedroom', 'Hallway'] as $room) {
        Room::factory()->for($this->home)->create(['name' => $room]);
    }
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->whereNot('kind', 'sensor')->whereNot('kind', 'lock')->update(['imported' => true]);
    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
});

function happen(object $test, string $entityId, string $state): void
{
    $test->ha->set($entityId, $state);
    $test->withToken($test->bearer)->postJson('/api/smart-home/v1/states', [
        'entity_id' => $entityId, 'state' => $state, 'last_changed' => now()->toIso8601ZuluString('millisecond'),
    ])->assertAccepted();
    $test->travel(1)->seconds();
}

function suggestion(Household $home, string $key): array
{
    return collect(Templates::suggestions($home))->firstWhere('key', $key);
}

it('suggests every workflow with the right devices from the test home', function () {
    $s = collect(Templates::suggestions($this->home))->keyBy('key');

    expect($s->every(fn ($t) => $t['ready']))->toBeTrue()
        ->and($s['welcome_light']['devices']['door'][0]['name'])->toBe('Front door')
        ->and($s['welcome_light']['devices']['light'][0]['name'])->toBe('Hallway light')
        ->and($s['dehumidifier']['devices']['humidity'][0]['name'])->toBe('Bathroom humidity')
        ->and($s['dehumidifier']['devices']['plug'][0]['name'])->toBe('Dehumidifier plug')
        ->and($s['night_window']['devices']['opening'][0]['name'])->toBe('Bedroom window')
        ->and($s['cold_window']['devices']['window'][0]['name'])->toBe('Bedroom window')
        ->and($s['cold_window']['devices']['temperature'][0]['name'])->toBe('Bedroom temperature')
        // Lights, and switches that say they're lights; not the dehumidifier or the coffee machine
        ->and(array_column($s['good_night']['devices']['lights'], 'name'))->toEqualCanonicalizing(['Living room lamp', 'Bedroom lamp', 'Kitchen ceiling', 'Hallway light']);
});

it('says what is missing for a household without the devices', function () {
    SmartDevice::query()->where('kind', 'humidity')->update(['imported' => false]);
    expect(suggestion($this->home, 'dehumidifier'))->ready->toBeFalse()->missing->toBe(['humidity']);
});

it('adds a workflow from the page, once', function () {
    $this->post('/smart-home/automations/templates/dehumidifier')->assertRedirect('/smart-home');

    expect(AutomationRule::orderBy('name')->pluck('name')->all())->toBe(['Dehumidifier off', 'Dehumidifier on'])
        ->and(AutomationRule::pluck('template')->unique()->all())->toBe(['dehumidifier'])
        ->and(suggestion($this->home, 'dehumidifier')['added'])->toBeTrue();
    $this->get('/smart-home')->assertInertia(fn (Page $p) => $p->has('automations.rules', 2)->has('automations.templates', 6));

    $this->post('/smart-home/automations/templates/nonsense')->assertNotFound();
});

it('runs the dehumidifier workflow after a shower', function () {
    $this->post('/smart-home/automations/templates/dehumidifier');
    foreach (['58', '66', '73', '75'] as $v) {
        happen($this, 'sensor.bathroom_humidity', $v);
    }
    expect($this->ha->states['switch.dehumidifier_plug']['state'])->toBe('on');

    $this->travel(15)->minutes();
    foreach (['68', '61', '59'] as $v) {
        happen($this, 'sensor.bathroom_humidity', $v);
    }
    expect($this->ha->states['switch.dehumidifier_plug']['state'])->toBe('off')
        ->and(AutomationRun::where('status', 'done')->count())->toBe(2);
});

it('runs the welcome light and the motion light after dark', function () {
    $this->post('/smart-home/automations/templates/welcome_light');
    happen($this, 'binary_sensor.front_door', 'on');
    expect($this->ha->states['switch.hallway_light']['state'])->toBe('on');

    happen($this, 'switch.hallway_light', 'off');
    $this->post('/smart-home/automations/templates/motion_light');
    happen($this, 'binary_sensor.hallway_motion', 'on');
    expect($this->ha->states['switch.hallway_light']['state'])->toBe('on');
    happen($this, 'binary_sensor.hallway_motion', 'off');
    expect($this->ha->states['switch.hallway_light']['state'])->toBe('off');
});

it('turns every light off at 00:30', function () {
    $this->post('/smart-home/automations/templates/good_night');
    foreach (['light.living_room_lamp', 'light.bedroom_lamp', 'switch.hallway_light'] as $id) {
        happen($this, $id, 'on');
    }

    $this->travelTo(CarbonImmutable::parse('2026-11-14 00:30:10', 'Europe/Athens'));
    expect(app(RuleEngine::class)->tick())->toBe(1);
    foreach (['light.living_room_lamp', 'light.bedroom_lamp', 'switch.hallway_light'] as $id) {
        expect($this->ha->states[$id]['state'])->toBe('off');
    }
});

it('notices a window opening at night and a cold room with its window open', function () {
    $this->post('/smart-home/automations/templates/night_window');
    $this->post('/smart-home/automations/templates/cold_window');

    $this->travelTo(CarbonImmutable::parse('2026-11-14 01:15', 'Europe/Athens'));
    happen($this, 'binary_sensor.bedroom_window', 'on');
    happen($this, 'sensor.bedroom_temperature', '17.6');

    $done = AutomationRun::where('status', 'done')->with('rule')->get()->pluck('rule.name')->all();
    expect($done)->toEqualCanonicalizing(['Window opened at night', 'Close the window, it\'s getting cold']);
    expect(AutomationRun::where('status', 'done')->get()->pluck('results.0.detail')->all())
        ->toContain('The room is below 18 °C and its window is open.');
});

it('lets the owner pick other devices for a workflow', function () {
    $lamp = SmartDevice::where('entity_id', 'light.living_room_lamp')->sole();
    $this->post('/smart-home/automations/templates/welcome_light', ['devices' => ['light' => [$lamp->id]]])->assertRedirect();

    expect(AutomationRule::sole()->actions[0]['device_id'])->toBe($lamp->id);

    $this->post('/smart-home/automations/templates/night_window', ['devices' => ['opening' => [str_repeat('z', 26)]]])
        ->assertSessionHasErrors();
});
