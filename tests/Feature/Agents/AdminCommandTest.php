<?php

use App\Enums\HouseholdRole;
use App\Models\AgentCommand;
use App\Models\Household;
use App\Models\User;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create(['email' => 'owner@example.test']);
    $this->member = User::factory()->create(['email' => 'member@example.test']);
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    $this->client = AgentClient::enrolled($this, $this->home, ['inventory' => ['hostname' => 'h', 'os' => ['name' => 'Linux'], 'disks' => [], 'audio' => [], 'commands' => [
        ['key' => 'disk_usage', 'description' => 'df', 'sensitive' => false, 'params' => []],
        ['key' => 'reboot', 'description' => 'Reboot', 'sensitive' => true, 'params' => []],
    ]]]);
});

it('lists and shows computers', function () {
    $this->artisan('agent:admin', ['action' => 'list'])->expectsOutputToContain('Mini PC')->assertSuccessful();
    $this->artisan('agent:admin', ['action' => 'show', 'target' => 'Mini PC'])->expectsOutputToContain('"inventory"')->assertSuccessful();
    $this->artisan('agent:admin', ['action' => 'show', 'target' => 'Nope'])->assertFailed();
});

it('queues commands under the same rules as the page', function () {
    $this->artisan('agent:admin', ['action' => 'run', 'target' => 'Mini PC', 'key' => 'disk_usage', '--email' => 'member@example.test'])->assertSuccessful();
    $this->artisan('agent:admin', ['action' => 'run', 'target' => 'Mini PC', 'key' => 'format_disk', '--email' => 'member@example.test'])->assertFailed();
    $this->artisan('agent:admin', ['action' => 'run', 'target' => 'Mini PC', 'key' => 'reboot', '--email' => 'member@example.test'])->assertSuccessful();

    $reboot = AgentCommand::where('command_key', 'reboot')->sole();
    expect($reboot->status)->toBe('awaiting_confirmation');
    $this->artisan('agent:admin', ['action' => 'confirm', 'target' => $reboot->id, '--email' => 'member@example.test'])->assertFailed();
    $this->artisan('agent:admin', ['action' => 'confirm', 'target' => $reboot->id, '--email' => 'owner@example.test'])->assertSuccessful();
    expect($reboot->fresh()->status)->toBe('queued');
});

it('revokes a computer', function () {
    $this->artisan('agent:admin', ['action' => 'revoke', 'target' => $this->client->agent->id])->assertSuccessful();
    expect($this->client->agent->fresh()->isRevoked())->toBeTrue();
});
