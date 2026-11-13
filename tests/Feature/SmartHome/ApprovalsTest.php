<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\HouseholdRole;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Device;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeAction;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Models\User;
use App\SmartHome\Automation\RuleValidator;
use App\SmartHome\Connections;
use App\SmartHome\Control;
use App\SmartHome\Discovery;
use App\SmartHome\Sensitivity;
use App\Web\CurrentHousehold;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't']]])]);
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Eleni']);
    $this->member = User::factory()->create(['name' => 'Nikos']);
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    Device::forceCreate(['user_id' => $this->owner->id, 'expo_token' => 'ExponentPushToken[owner]']);
    Device::forceCreate(['user_id' => $this->member->id, 'expo_token' => 'ExponentPushToken[member]']);
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->whereNot('kind', 'sensor')->update(['imported' => true]);
    $this->control = app(Control::class);
    $this->lock = SmartDevice::where('entity_id', 'lock.front_door_lock')->sole();
    $this->coffee = SmartDevice::where('entity_id', 'switch.coffee_machine_plug')->sole();
});

/** @return list<string> Expo tokens a push was sent to */
function pushedTo(): array
{
    return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'exp.host'))
        ->flatMap(fn ($p) => array_column($p[0]->data(), 'to'))->values()->all();
}

it('marks locks, climate and things that heat as sensitive when found', function () {
    expect($this->lock->sensitive)->toBeTrue()
        ->and($this->coffee->sensitive)->toBeTrue()
        ->and(SmartDevice::where('entity_id', 'switch.dehumidifier_plug')->value('sensitive'))->toBeFalse()
        ->and(SmartDevice::where('entity_id', 'light.living_room_lamp')->value('sensitive'))->toBeFalse();
});

it('only the risky direction needs approval', function () {
    expect(Sensitivity::needsApproval($this->lock, 'unlock'))->toBeTrue()
        ->and(Sensitivity::needsApproval($this->lock, 'lock'))->toBeFalse()
        ->and(Sensitivity::needsApproval($this->coffee, 'turn_on'))->toBeTrue()
        ->and(Sensitivity::needsApproval($this->coffee, 'turn_off'))->toBeFalse();
});

it('makes a member\'s unlock wait for the owner, and asks only the owner', function () {
    $row = $this->control->request($this->lock, $this->member, 'unlock');

    expect($row)->status->toBe('awaiting_approval')->expires_at->not->toBeNull()
        ->and($this->ha->calls)->toBe([])
        ->and($this->ha->states['lock.front_door_lock']['state'])->toBe('locked')
        ->and(SmartHomeEvent::where('type', 'approval.requested')->value('message'))->toBe('Nikos wants to: Unlock Front door lock. Approve or decline in Home Helper.')
        ->and(pushedTo())->toBe(['ExponentPushToken[owner]']);
});

it('lets the owner approve, and then it happens', function () {
    $row = $this->control->request($this->lock, $this->member, 'unlock');
    $row = $this->control->approve($row, $this->owner);

    expect($row)->status->toBe('done')->decided_by->toBe($this->owner->id)
        ->and($this->ha->states['lock.front_door_lock']['state'])->toBe('unlocked');
});

it('lets the owner decline, and nothing happens', function () {
    $row = $this->control->decline($this->control->request($this->lock, $this->member, 'unlock'), $this->owner);
    expect($row->status)->toBe('declined')->and($this->ha->calls)->toBe([]);

    // Answered is answered
    expect(fn () => $this->control->approve($row, $this->owner))->toThrow(ValidationException::class, 'no longer waiting');
});

it('does not let a member approve', function () {
    $row = $this->control->request($this->lock, $this->member, 'unlock');
    expect(fn () => $this->control->approve($row, $this->member))->toThrow(ValidationException::class, 'Only the household owner');
});

it('expires requests nobody answered', function () {
    $row = $this->control->request($this->coffee, $this->member, 'turn_on');
    $this->travel(11)->minutes();

    expect(fn () => $this->control->approve($row, $this->owner))->toThrow(ValidationException::class, 'no longer waiting');
    $this->artisan('smart-home:automations')->assertSuccessful();
    expect($row->fresh()->status)->toBe('expired');
});

it('re-checks the device when approved: unplugged in the meantime means failed, not a hang', function () {
    $row = $this->control->request($this->coffee, $this->member, 'turn_on');
    $this->ha->set('switch.coffee_machine_plug', 'unavailable');
    app(Discovery::class)->sync($this->connection);

    $row = $this->control->approve($row, $this->owner);
    expect($row)->status->toBe('failed')->error->toContain('isn\'t answering');
});

it('lets the owner act directly from the dashboard', function () {
    $row = $this->control->request($this->lock, $this->owner, 'unlock');
    expect($row)->status->toBe('done')->decided_by->toBe($this->owner->id);
});

it('lets anyone lock up or switch the heater off straight away', function () {
    $this->ha->set('lock.front_door_lock', 'unlocked');
    $this->ha->set('switch.coffee_machine_plug', 'on');
    app(Discovery::class)->sync($this->connection);

    expect($this->control->request($this->lock->fresh(), $this->member, 'lock')->status)->toBe('done')
        ->and($this->control->request($this->coffee->fresh(), $this->member, 'turn_off')->status)->toBe('done');
});

it('makes an automation wait too, even though the owner wrote it', function () {
    $data = app(RuleValidator::class)->validate($this->home, [
        'name' => 'Coffee at seven', 'trigger' => ['type' => 'time', 'at' => '07:00'], 'conditions' => [],
        'actions' => [['type' => 'device', 'device_id' => $this->coffee->id, 'action' => 'turn_on']],
    ]);
    $rule = new AutomationRule($data + ['created_by' => $this->owner->id]);
    $rule->household_id = $this->home->id;
    $rule->save();

    $this->travelTo(now()->setTimezone('Europe/Athens')->setTime(7, 0, 5));
    $this->artisan('smart-home:automations')->assertSuccessful();

    expect(AutomationRun::sole()->status)->toBe('awaiting_approval')
        ->and(SmartHomeAction::sole())->status->toBe('awaiting_approval')->source->toBe('rule')
        ->and(SmartHomeEvent::where('type', 'approval.requested')->value('message'))->toStartWith('An automation wants to: Turn on Coffee machine plug');
});

it('never lets the assistant unlock, even for the owner: it becomes a request', function () {
    $registry = app(ToolRegistry::class);
    $context = new ToolContext($this->home, $this->owner, now()->toImmutable());
    $tool = $registry->find('control_device');

    $result = $tool->run(['device' => 'Front door lock', 'action' => 'unlock'], $context);
    expect($result)->toHaveKey('waiting_for_owner')
        ->and(SmartHomeAction::sole())->status->toBe('awaiting_approval')->source->toBe('assistant')
        ->and($this->ha->states['lock.front_door_lock']['state'])->toBe('locked');

    // Ordinary devices just switch (after the person confirmed the proposal in the chat)
    expect($tool->run(['device' => 'Living room lamp', 'action' => 'turn_on'], $context))->toHaveKey('done')
        ->and($tool->run(['device' => 'Nope', 'action' => 'turn_on'], $context))->toHaveKey('error');
});

it('tells the assistant about the house', function () {
    $result = app(ToolRegistry::class)->find('get_smart_home')->run([], new ToolContext($this->home, $this->member, now()->toImmutable()));

    expect($result['home_assistant'])->toBe('answering')
        ->and(collect($result['devices'])->firstWhere('name', 'Front door lock'))->toMatchArray(['state' => 'Locked', 'needs_owner_approval_to' => 'unlock'])
        ->and(collect($result['devices'])->firstWhere('name', 'Coffee machine plug')['needs_owner_approval_to'])->toBe('turn on');
});

describe('the page and the phone', function () {
    it('shows what is waiting and lets the owner answer', function () {
        $row = $this->control->request($this->lock, $this->member, 'unlock');
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);

        $this->get('/smart-home')->assertInertia(fn (Page $p) => $p->where('approvals.0.label', 'Unlock Front door lock')->where('approvals.0.requested_by', 'Nikos')->where('can.approve', true));
        $this->post("/smart-home/actions/{$row->id}/approve")->assertRedirect('/smart-home');
        expect($row->fresh()->status)->toBe('done');
    });

    it('answers a member\'s unlock with 202 and lets the owner approve from the phone', function () {
        Sanctum::actingAs($this->member);
        $id = $this->postJson("/api/households/{$this->home->id}/smart-home/devices/{$this->lock->id}/actions", ['action' => 'unlock'])
            ->assertStatus(202)->assertJsonPath('action.status', 'awaiting_approval')->json('action.id');

        $this->postJson("/api/households/{$this->home->id}/smart-home/approvals/{$id}/approve")->assertUnprocessable();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/households/{$this->home->id}/smart-home/approvals/{$id}/approve")->assertOk()->assertJsonPath('action.status', 'done');
    });

    it('lets the owner mark a device sensitive, but never unmark a lock', function () {
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
        $lamp = SmartDevice::where('entity_id', 'light.living_room_lamp')->sole();

        $this->patch("/smart-home/devices/{$lamp->id}", ['sensitive' => true])->assertRedirect();
        $this->patch("/smart-home/devices/{$this->lock->id}", ['sensitive' => false])->assertRedirect();
        expect($lamp->fresh()->sensitive)->toBeTrue()->and($this->lock->fresh()->sensitive)->toBeTrue();

        expect($this->control->request($lamp->fresh(), $this->member, 'turn_on')->status)->toBe('awaiting_approval');
    });
});
