<?php

use App\Models\AgentMetric;
use App\Models\Household;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->client = AgentClient::enrolled($this, $this->home, ['last_seen_at' => now()->subHour()]);
});

function heartbeat(array $extra = []): array
{
    return $extra + [
        'version' => '0.5.1',
        'platform' => 'windows-x64',
        'git_sha' => 'abc123',
        'started_at' => '2026-11-12T10:00:00Z',
        'uptime_seconds' => 86400,
        'outbox' => ['pending' => 3, 'dropped' => 0],
        'metrics' => [
            'cpu_percent' => 12.5,
            'memory' => ['total_bytes' => 16_000_000_000, 'used_bytes' => 4_000_000_000],
            'disks' => [
                ['mount' => 'C:\\', 'total_bytes' => 500_000_000_000, 'free_bytes' => 100_000_000_000],
                ['mount' => 'D:\\', 'total_bytes' => 1_000_000_000_000, 'free_bytes' => 50_000_000_000],
            ],
            'temperatures' => [['label' => 'CPU', 'celsius' => 54], ['label' => 'NVMe', 'celsius' => 61.25]],
        ],
    ];
}

it('records the status and the latest readings', function () {
    $this->client->post('heartbeat', heartbeat())
        ->assertOk()
        ->assertJsonPath('heartbeat_seconds', 30)
        ->assertJsonPath('commands', [])
        ->assertJsonPath('update', null)
        ->assertJsonPath('inventory_wanted', true);

    $agent = $this->client->agent->fresh();
    expect($agent->version)->toBe('0.5.1')
        ->and($agent->uptime_seconds)->toBe(86400)
        ->and($agent->outbox_pending)->toBe(3)
        ->and($agent->metrics['cpu_percent'])->toBe(12.5)
        ->and($agent->started_at->toIso8601ZuluString())->toBe('2026-11-12T10:00:00Z')
        ->and($agent->isOnline())->toBeTrue()
        ->and($agent->last_seen_at->diffInSeconds(now(), true))->toBeLessThan(5);
});

it('keeps one summary row a minute for the charts', function () {
    $this->client->post('heartbeat', heartbeat())->assertOk();
    $this->client->post('heartbeat', heartbeat())->assertOk();

    expect(AgentMetric::count())->toBe(1);
    $row = AgentMetric::first();
    expect($row->cpu_percent)->toBe(12.5)
        ->and($row->memory_percent)->toBe(25.0)
        ->and($row->disk_used_percent)->toBe(95.0) // D: is the fullest
        ->and($row->temperature_c)->toBe(61.3);

    $this->travel(61)->seconds();
    $this->client->post('heartbeat', heartbeat())->assertOk();
    expect(AgentMetric::count())->toBe(2);
});

it('does not ask again for an inventory it has', function () {
    $this->client->agent->forceFill(['inventory_hash' => str_repeat('a', 128)])->save();

    $this->client->post('heartbeat', heartbeat(['inventory_hash' => str_repeat('a', 128)]))
        ->assertOk()->assertJsonPath('inventory_wanted', false);
    $this->client->post('heartbeat', heartbeat(['inventory_hash' => str_repeat('b', 128)]))
        ->assertOk()->assertJsonPath('inventory_wanted', true);
});

it('works without readings (a monitor that failed)', function () {
    $this->client->post('heartbeat', ['version' => '0.5.0', 'platform' => 'linux-arm64'])->assertOk();
    expect(AgentMetric::count())->toBe(0);
});

it('rejects readings that make no sense', function () {
    $this->client->post('heartbeat', heartbeat(['metrics' => ['cpu_percent' => 250, 'surprise' => 1]]))
        ->assertStatus(422);
    $this->client->post('heartbeat', heartbeat(['version' => 'latest']))->assertStatus(422);
});

it('shows offline after four missed heartbeats', function () {
    $this->client->post('heartbeat', heartbeat())->assertOk();
    $agent = $this->client->agent->fresh();

    expect($agent->isOnline(now()->addSeconds(100)->toImmutable()))->toBeTrue()
        ->and($agent->isOnline(now()->addSeconds(130)->toImmutable()))->toBeFalse();
});

it('prunes chart rows after a week', function () {
    $this->client->post('heartbeat', heartbeat())->assertOk();
    $this->travel(8)->days();
    $this->artisan('model:prune', ['--model' => [AgentMetric::class]])->assertSuccessful();

    expect(AgentMetric::count())->toBe(0);
});
