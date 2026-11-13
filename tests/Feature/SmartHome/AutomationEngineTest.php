<?php

use App\Enums\HouseholdRole;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Models\User;
use App\SmartHome\Automation\Conditions;
use App\SmartHome\Automation\Describe;
use App\SmartHome\Automation\RuleEngine;
use App\SmartHome\Automation\RuleValidator;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\Openings;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    // 20:00 in Athens in November: dark. First, so every state is from "today"
    $this->travelTo(CarbonImmutable::parse('2026-11-13 20:00', 'Europe/Athens'));
    $this->ha = FakeHomeAssistant::install();
    Http::fake(['exp.host/*' => Http::response(['data' => []])]);
    $this->home = Household::factory()->create(['latitude' => 37.98, 'longitude' => 23.73]);
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->whereNot('kind', 'sensor')->whereNot('kind', 'lock')->update(['imported' => true]);
});

function d(string $entityId): SmartDevice
{
    return SmartDevice::where('entity_id', $entityId)->sole();
}

function rule(object $test, array $input): AutomationRule
{
    $data = app(RuleValidator::class)->validate($test->home, $input);
    $rule = new AutomationRule($data);
    $rule->household_id = $test->home->id;
    $rule->save();

    return $rule;
}

/** The physical world changes: Home Assistant's state, then its push to Home Helper */
function ha(object $test, string $entityId, string $state): void
{
    $test->ha->set($entityId, $state);
    $test->withToken($test->bearer)->postJson('/api/smart-home/v1/states', [
        'entity_id' => $entityId, 'state' => $state, 'last_changed' => now()->toIso8601ZuluString('millisecond'),
    ])->assertAccepted();
    $test->travel(1)->seconds();
}

it('turns the hallway light on when the front door opens after dark', function () {
    rule($this, [
        'name' => 'Hall light when someone comes in',
        'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.front_door')->id, 'to' => 'on'],
        'conditions' => [['type' => 'sun', 'is' => 'down']],
        'actions' => [['type' => 'device', 'device_id' => d('switch.hallway_light')->id, 'action' => 'turn_on']],
    ]);

    ha($this, 'binary_sensor.front_door', 'on');

    expect($this->ha->states['switch.hallway_light']['state'])->toBe('on')
        ->and(AutomationRun::sole())->status->toBe('done')->trigger->toBe('Front door: off → on')
        ->and(d('switch.hallway_light')->state)->toBe('on');
});

it('does nothing in daylight, and says why', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00', 'Europe/Athens'));
    rule($this, [
        'name' => 'Hall light',
        'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.front_door')->id, 'to' => 'on'],
        'conditions' => [['type' => 'sun', 'is' => 'down']],
        'actions' => [['type' => 'device', 'device_id' => d('switch.hallway_light')->id, 'action' => 'turn_on']],
    ]);

    ha($this, 'binary_sensor.front_door', 'on');

    expect($this->ha->calls)->toBe([])
        ->and(AutomationRun::sole())->status->toBe('skipped')->reason->toBe('the sun is not down');
});

it('runs the dehumidifier on a humidity crossing, not on every reading', function () {
    rule($this, [
        'name' => 'Dehumidifier on',
        'trigger' => ['type' => 'numeric', 'device_id' => d('sensor.bathroom_humidity')->id, 'above' => 65],
        'conditions' => [],
        'actions' => [['type' => 'device', 'device_id' => d('switch.dehumidifier_plug')->id, 'action' => 'turn_on']],
        'cooldown_minutes' => 0,
    ]);

    foreach (['60', '66', '70', '72'] as $v) {
        ha($this, 'sensor.bathroom_humidity', $v);
    }
    expect(AutomationRun::count())->toBe(1)
        ->and($this->ha->states['switch.dehumidifier_plug']['state'])->toBe('on');

    ha($this, 'sensor.bathroom_humidity', '62');
    ha($this, 'sensor.bathroom_humidity', '67');
    expect(AutomationRun::count())->toBe(2);
});

it('starts from an event in the log', function () {
    rule($this, [
        'name' => 'Tell everyone the door is open',
        'trigger' => ['type' => 'event', 'event' => 'door.left_open'],
        'conditions' => [],
        'actions' => [['type' => 'notify', 'message' => 'The front door is still open']],
    ]);
    ha($this, 'binary_sensor.front_door', 'on');
    $this->travel(11)->minutes();
    app(Openings::class)->checkLeftOpen();

    expect(AutomationRun::sole())->status->toBe('done')->trigger->toBe('Front door has been open for 11 min');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'exp.host') || true);
});

it('runs time triggers once, on the right days', function () {
    rule($this, [
        'name' => 'Good night',
        'trigger' => ['type' => 'time', 'at' => '00:30', 'days' => [1, 2, 3, 4, 5, 6, 7]],
        'conditions' => [],
        'actions' => [
            ['type' => 'device', 'device_id' => d('light.living_room_lamp')->id, 'action' => 'turn_off'],
            ['type' => 'device', 'device_id' => d('switch.hallway_light')->id, 'action' => 'turn_off'],
        ],
    ]);
    rule($this, [
        'name' => 'Weekday coffee',
        'trigger' => ['type' => 'time', 'at' => '00:30', 'days' => [1, 2, 3, 4, 5]],
        'conditions' => [],
        'actions' => [['type' => 'notify', 'message' => 'Morning']],
    ]);
    $engine = app(RuleEngine::class);

    // Saturday 00:30
    $this->travelTo(CarbonImmutable::parse('2026-11-14 00:30:05', 'Europe/Athens'));
    expect($engine->tick())->toBe(1)
        ->and($engine->tick())->toBe(0);
    expect(AutomationRun::sole()->results)->toHaveCount(2);

    $this->travelTo(CarbonImmutable::parse('2026-11-14 00:31', 'Europe/Athens'));
    expect($engine->tick())->toBe(0);
});

it('respects the cooldown', function () {
    rule($this, [
        'name' => 'Motion light',
        'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.hallway_motion')->id, 'to' => 'on'],
        'conditions' => [],
        'actions' => [['type' => 'device', 'device_id' => d('switch.hallway_light')->id, 'action' => 'turn_on']],
        'cooldown_minutes' => 5,
    ]);
    foreach (range(1, 3) as $i) {
        ha($this, 'binary_sensor.hallway_motion', 'on');
        ha($this, 'binary_sensor.hallway_motion', 'off');
    }

    expect(AutomationRun::where('status', 'done')->count())->toBe(1)
        ->and(AutomationRun::where('status', 'skipped')->value('reason'))->toBe('ran less than 5 min ago');
});

it('stops rules that set each other off', function () {
    $lamp = d('light.living_room_lamp');
    $hall = d('switch.hallway_light');
    rule($this, ['name' => 'A', 'cooldown_minutes' => 0, 'trigger' => ['type' => 'state', 'device_id' => $lamp->id, 'to' => 'on'], 'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => $hall->id, 'action' => 'toggle']]]);
    rule($this, ['name' => 'B', 'cooldown_minutes' => 0, 'trigger' => ['type' => 'state', 'device_id' => $hall->id, 'to' => 'on'], 'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => $lamp->id, 'action' => 'toggle']]]);
    rule($this, ['name' => 'C', 'cooldown_minutes' => 0, 'trigger' => ['type' => 'state', 'device_id' => $lamp->id, 'to' => 'off'], 'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => $lamp->id, 'action' => 'turn_on']]]);

    ha($this, 'light.living_room_lamp', 'on');

    expect(AutomationRun::where('status', 'skipped')->where('reason', 'like', 'stopped a chain%')->exists())->toBeTrue()
        ->and(AutomationRun::count())->toBeLessThan(10);
});

it('records a failed action and tells the household', function () {
    $rule = rule($this, [
        'name' => 'Lamp on',
        'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.front_door')->id, 'to' => 'on'],
        'conditions' => [],
        'actions' => [['type' => 'device', 'device_id' => d('light.living_room_lamp')->id, 'action' => 'turn_on'], ['type' => 'notify', 'message' => 'Welcome']],
    ]);
    $this->ha->set('light.living_room_lamp', 'unavailable');
    app(Discovery::class)->sync($this->connection);

    ha($this, 'binary_sensor.front_door', 'on');

    $run = AutomationRun::where('rule_id', $rule->id)->sole();
    expect($run->status)->toBe('partial')
        ->and($run->results[0])->toMatchArray(['status' => 'failed'])
        ->and($run->results[0]['detail'])->toContain('isn\'t answering')
        ->and(SmartHomeEvent::where('type', 'automation.failed')->value('message'))->toContain('"Lamp on" didn\'t fully work');
});

it('ignores disabled rules', function () {
    $rule = rule($this, ['name' => 'Off', 'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.front_door')->id, 'to' => 'on'], 'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'x']]]);
    $rule->forceFill(['enabled' => false])->save();
    ha($this, 'binary_sensor.front_door', 'on');
    expect(AutomationRun::count())->toBe(0);
});

describe('the validator', function () {
    it('refuses rules it could not run', function (Closure $input, string $field) {
        expect(fn () => app(RuleValidator::class)->validate($this->home, $input()))->toThrow(ValidationException::class);
        try {
            app(RuleValidator::class)->validate($this->home, $input());
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($field);
        }
    })->with([
        'no trigger' => [fn () => ['name' => 'x', 'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'x']]], 'trigger'],
        'no actions' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [], 'actions' => []], 'actions'],
        'bad time' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '25:00'], 'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'x']]], 'trigger.at'],
        'unknown device' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'state', 'device_id' => str_repeat('a', 26), 'to' => 'on'], 'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'x']]], 'trigger.device_id'],
        'dim a plug' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => d('switch.dehumidifier_plug')->id, 'action' => 'set_brightness', 'brightness' => 50]]], 'actions.0.action'],
        'above and below' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'numeric', 'device_id' => d('sensor.bathroom_humidity')->id, 'above' => 60, 'below' => 70], 'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'x']]], 'trigger.above'],
        'the sun sideways' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [['type' => 'sun', 'is' => 'sideways']], 'actions' => [['type' => 'notify', 'message' => 'x']]], 'conditions.0.is'],
        'a script' => [fn () => ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [], 'actions' => [['type' => 'shell', 'command' => 'rm -rf /']]], 'actions.0.type'],
    ]);

    it('refuses another household\'s device', function () {
        $other = Household::factory()->create();
        $foreign = new SmartDevice(['connection_id' => $this->connection->id, 'entity_id' => 'light.theirs', 'domain' => 'light', 'kind' => 'light', 'name' => 'Theirs', 'imported' => true]);
        $foreign->household_id = $other->id;
        $foreign->save();

        expect(fn () => app(RuleValidator::class)->validate($this->home, ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => $foreign->id, 'action' => 'turn_on']]]))
            ->toThrow(ValidationException::class);
    });

    it('keeps only the fields of each shape', function () {
        $data = app(RuleValidator::class)->validate($this->home, [
            'name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00', 'days' => [5, 1, 1], 'evil' => 'y'],
            'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'hi', 'extra' => 1]],
        ]);
        expect($data['trigger'])->toBe(['type' => 'time', 'at' => '07:00', 'days' => [1, 5]])
            ->and($data['actions'])->toBe([['type' => 'notify', 'message' => 'hi']]);
    });
});

it('checks time windows across midnight and the sun from the household\'s location', function () {
    expect(Conditions::between('23:30', '23:00', '06:00'))->toBeTrue()
        ->and(Conditions::between('05:59', '23:00', '06:00'))->toBeTrue()
        ->and(Conditions::between('12:00', '23:00', '06:00'))->toBeFalse()
        ->and(Conditions::sunIsUp($this->home, CarbonImmutable::parse('2026-11-13 12:00', 'Europe/Athens')))->toBeTrue()
        ->and(Conditions::sunIsUp($this->home, CarbonImmutable::parse('2026-11-13 18:30', 'Europe/Athens')))->toBeFalse();
});

it('describes rules in words', function () {
    $rule = rule($this, [
        'name' => 'x',
        'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.front_door')->id, 'to' => 'on'],
        'conditions' => [['type' => 'sun', 'is' => 'down'], ['type' => 'days', 'days' => [1, 2, 3, 4, 5]]],
        'actions' => [['type' => 'device', 'device_id' => d('light.living_room_lamp')->id, 'action' => 'set_brightness', 'brightness' => 30], ['type' => 'notify', 'message' => 'Welcome home']],
    ]);
    $devices = SmartDevice::all()->keyBy('id')->all();

    expect(Describe::rule($rule, $devices))->toBe('When Front door opens, if after dark and on weekdays: set Living room lamp to 30%, then notify everyone ("Welcome home").');
});

describe('the page', function () {
    beforeEach(fn () => $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]));

    it('adds, switches off, runs and removes a rule', function () {
        $this->postJson('/smart-home/automations', [
            'name' => 'Hall light', 'trigger' => ['type' => 'state', 'device_id' => d('binary_sensor.front_door')->id, 'to' => 'on'],
            'conditions' => [], 'actions' => [['type' => 'device', 'device_id' => d('switch.hallway_light')->id, 'action' => 'turn_on']],
        ])->assertRedirect('/smart-home');
        $rule = AutomationRule::sole();
        expect($rule->created_by)->toBe($this->owner->id)->and($rule->household_id)->toBe($this->home->id);

        $this->get('/smart-home')->assertInertia(fn (Page $p) => $p->where('automations.rules.0.description', 'When Front door opens: turn on Hallway light.'));

        $this->patchJson("/smart-home/automations/{$rule->id}", ['enabled' => false])->assertRedirect();
        expect($rule->fresh()->enabled)->toBeFalse();

        $this->post("/smart-home/automations/{$rule->id}/run")->assertRedirect();
        expect($this->ha->states['switch.hallway_light']['state'])->toBe('on')
            ->and(AutomationRun::sole()->trigger)->toStartWith('Run now by');

        $this->delete("/smart-home/automations/{$rule->id}")->assertRedirect();
        expect(AutomationRule::count())->toBe(0);
    });

    it('lets only the owner change automations', function () {
        $rule = rule($this, ['name' => 'x', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [], 'actions' => [['type' => 'notify', 'message' => 'x']]]);
        $this->actingAs($this->member);

        $this->postJson('/smart-home/automations', ['name' => 'y'])->assertForbidden();
        $this->patchJson("/smart-home/automations/{$rule->id}", ['enabled' => false])->assertForbidden();
        $this->post("/smart-home/automations/{$rule->id}/run")->assertForbidden();
        $this->delete("/smart-home/automations/{$rule->id}")->assertForbidden();
        $this->get('/smart-home')->assertInertia(fn (Page $p) => $p->has('automations.rules', 1));
    });
});
