<?php

use App\Agents\AgentEvents;
use App\Agents\Enrollment;
use App\Enums\HouseholdRole;
use App\Models\AgentEvent;
use App\Models\Household;
use App\Models\User;
use App\Push\ExpoPush;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->client = AgentClient::enrolled($this, $this->home);
    Http::fake(['exp.host/*' => Http::response(['data' => []])]);
});

function agentEvent(array $extra = []): array
{
    return $extra + [
        'id' => (string) Str::uuid(),
        'type' => 'disk.low',
        'severity' => 'warning',
        'message' => 'C:\\ has 4.1 GB free (3%)',
        'data' => ['mount' => 'C:\\', 'free_percent' => 3.2],
        'occurred_at' => now()->subMinute()->toIso8601ZuluString('millisecond'),
    ];
}

it('stores a batch of events and recognises a resend', function () {
    $batch = [agentEvent(), agentEvent(['type' => 'audio.added', 'severity' => 'info', 'message' => 'Microphone connected: Blue Yeti', 'data' => null])];

    $this->client->post('events', ['events' => $batch])->assertOk()->assertExactJson(['accepted' => 2, 'duplicates' => 0]);
    $this->client->post('events', ['events' => $batch])->assertOk()->assertExactJson(['accepted' => 0, 'duplicates' => 2]);

    $stored = AgentEvent::orderBy('type')->get();
    expect($stored)->toHaveCount(2)
        ->and($stored[0]->type)->toBe('audio.added')
        ->and($stored[1]->data['mount'])->toBe('C:\\')
        ->and($stored[1]->household_id)->toBe($this->home->id)
        ->and($stored[1]->source)->toBe('agent');
});

it('never dates an event in the future, whatever the agent\'s clock says', function () {
    $this->client->post('events', ['events' => [agentEvent(['occurred_at' => now()->addDay()->toIso8601ZuluString()])]])->assertOk();
    expect(AgentEvent::first()->occurred_at->lessThanOrEqualTo(now()))->toBeTrue();
});

it('rejects malformed events', function () {
    $this->client->post('events', ['events' => [agentEvent(['id' => 'not-a-uuid'])]])->assertStatus(422);
    $this->client->post('events', ['events' => [agentEvent(['type' => 'DROP TABLE'])]])->assertStatus(422);
    $this->client->post('events', ['events' => [agentEvent(['severity' => 'apocalyptic'])]])->assertStatus(422);
    $this->client->post('events', ['events' => array_fill(0, 101, agentEvent())])->assertStatus(422);
});

it('pushes warnings to the household\'s phones, at most once an hour per kind', function () {
    $push = Mockery::mock(ExpoPush::class);
    $push->shouldReceive('toUsers')->once()->withArgs(fn ($users, $title, $body) => str_contains($title, 'Mini PC') && str_contains($body, 'free'))->andReturn([]);
    app()->instance(ExpoPush::class, $push);

    $this->client->post('events', ['events' => [agentEvent()]])->assertOk();
    $this->client->post('events', ['events' => [agentEvent()]])->assertOk();  // another disk.low within the hour
    $this->client->post('events', ['events' => [agentEvent(['type' => 'audio.removed', 'severity' => 'info'])]])->assertOk();
});

it('records enrollment as a server event', function () {
    [$code] = app(Enrollment::class)->createCode($this->home, $this->owner);
    $this->postJson('/api/agent/v1/enroll', ['code' => $code, 'platform' => 'linux-arm64', 'version' => '0.5.0', 'hostname' => 'raspberrypi'])->assertCreated();

    $event = AgentEvent::where('type', 'agent.enrolled')->sole();
    expect($event->source)->toBe('server')->and($event->data['hostname'])->toBe('raspberrypi');
});

it('a push service outage does not lose the event', function () {
    $push = Mockery::mock(ExpoPush::class);
    $push->shouldReceive('toUsers')->andThrow(new RuntimeException('Expo is down'));
    app()->instance(ExpoPush::class, $push);

    app(AgentEvents::class)->record($this->client->agent, 'agent.offline', 'warning', 'Mini PC stopped checking in');
    expect(AgentEvent::where('type', 'agent.offline')->count())->toBe(1);
});
