<?php

use App\Agents\Inventory;
use App\Models\Household;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->client = AgentClient::enrolled($this, Household::factory()->create());
});

function inventory(array $extra = []): array
{
    return $extra + [
        'hostname' => 'MINIPC',
        'os' => ['name' => 'Windows', 'version' => '10.0.26300'],
        'cpu' => ['model' => 'Intel(R) N100', 'cores' => 4],
        'memory_bytes' => 16_000_000_000,
        'disks' => [['mount' => 'C:\\', 'total_bytes' => 512_000_000_000, 'filesystem' => 'NTFS']],
        'audio' => [
            ['id' => '{0.0.1}.{mic}', 'name' => 'Microphone (USB Audio)', 'direction' => 'input', 'default' => true, 'state' => 'active'],
            ['id' => '{0.0.0}.{spk}', 'name' => 'Speakers (Realtek)', 'direction' => 'output', 'default' => true, 'state' => 'active'],
            ['id' => '{0.0.1}.{jack}', 'name' => 'External Microphone', 'direction' => 'input', 'default' => false, 'state' => 'unplugged'],
        ],
        'commands' => [
            ['key' => 'ping_router', 'description' => 'Ping a device', 'sensitive' => false,
                'params' => [['name' => 'host', 'description' => '192.168.x.x', 'pattern' => '^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$']]],
            ['key' => 'restart_computer', 'description' => 'Restart in one minute', 'sensitive' => true, 'params' => []],
        ],
    ];
}

it('stores the inventory and its hash, so heartbeats stop asking', function () {
    $body = inventory();
    $this->client->post('inventory', $body)->assertNoContent();

    $agent = $this->client->agent->fresh();
    $hash = hash('sha512', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    expect($agent->inventory_hash)->toBe($hash)
        ->and($agent->hostname)->toBe('MINIPC')
        ->and(Inventory::audio($agent, 'input'))->toHaveCount(2)
        ->and(Inventory::audio($agent, 'output')[0]['name'])->toBe('Speakers (Realtek)')
        ->and(Inventory::command($agent, 'restart_computer')['sensitive'])->toBeTrue()
        ->and(Inventory::command($agent, 'format_disk'))->toBeNull();

    $this->client->post('heartbeat', ['version' => '0.5.0', 'platform' => 'windows-x64', 'inventory_hash' => $hash])
        ->assertJsonPath('inventory_wanted', false);
});

it('rejects malformed audio devices and command keys', function () {
    $this->client->post('inventory', inventory(['audio' => [['id' => 'x', 'name' => 'y', 'direction' => 'sideways', 'default' => true, 'state' => 'active']]]))
        ->assertStatus(422)->assertJsonValidationErrors('audio.0.direction');
    $this->client->post('inventory', inventory(['commands' => [['key' => 'Rm -rf', 'description' => 'x', 'sensitive' => false, 'params' => []]]]))
        ->assertStatus(422)->assertJsonValidationErrors('commands.0.key');
});

it('accepts a computer with no audio and no commands', function () {
    $this->client->post('inventory', inventory(['audio' => [], 'commands' => []]))->assertNoContent();
    expect(Inventory::commands($this->client->agent->fresh()))->toBe([]);
});
