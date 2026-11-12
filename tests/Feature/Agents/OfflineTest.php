<?php

use App\Models\AgentCommand;
use App\Models\AgentEvent;
use App\Models\Household;
use App\Push\ExpoPush;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->client = AgentClient::enrolled($this, Household::factory()->create());
    $push = Mockery::mock(ExpoPush::class);
    $push->shouldReceive('toUsers')->andReturn([]);
    app()->instance(ExpoPush::class, $push);
});

function heartbeatOk(AgentClient $client): void
{
    $client->post('heartbeat', ['version' => '0.5.0', 'platform' => 'windows-x64'])->assertOk();
}

it('records a computer that stopped checking in, once', function () {
    heartbeatOk($this->client);
    $this->travel(100)->seconds();
    $this->artisan('agents:check-offline')->assertSuccessful();
    expect(AgentEvent::where('type', 'agent.offline')->count())->toBe(0);  // within four heartbeats

    $this->travel(60)->seconds();
    $this->artisan('agents:check-offline')->assertSuccessful();
    $this->artisan('agents:check-offline')->assertSuccessful();

    $event = AgentEvent::where('type', 'agent.offline')->sole();
    expect($event->severity)->toBe('warning')
        ->and($event->source)->toBe('server')
        ->and($event->message)->toContain('stopped checking in');
});

it('says it is back with the next heartbeat', function () {
    heartbeatOk($this->client);
    $this->travel(10)->minutes();
    $this->artisan('agents:check-offline');

    heartbeatOk($this->client);
    expect(AgentEvent::where('type', 'agent.online')->count())->toBe(1)
        ->and($this->client->agent->fresh()->offline_reported_at)->toBeNull();

    heartbeatOk($this->client);
    expect(AgentEvent::where('type', 'agent.online')->count())->toBe(1);
});

it('leaves removed and never-seen computers alone', function () {
    $this->client->agent->update(['revoked_at' => now(), 'last_seen_at' => now()->subDay()]);
    AgentClient::enrolled($this, $this->client->agent->household, ['name' => 'New', 'last_seen_at' => null]);

    $this->artisan('agents:check-offline')->assertSuccessful();
    expect(AgentEvent::count())->toBe(0);
});

it('a command for a computer that stays offline expires instead of running late', function () {
    $command = new AgentCommand(['agent_id' => $this->client->agent->id, 'source' => 'dashboard', 'command_key' => 'x', 'params' => [], 'sensitive' => false, 'status' => 'queued', 'expires_at' => now()->addMinutes(10)]);
    $command->household_id = $this->client->agent->household_id;
    $command->save();

    $this->travel(30)->minutes();
    $this->client->post('heartbeat', ['version' => '0.5.0', 'platform' => 'windows-x64'])->assertJsonPath('commands', []);
    expect($command->fresh()->status)->toBe('expired');
});
