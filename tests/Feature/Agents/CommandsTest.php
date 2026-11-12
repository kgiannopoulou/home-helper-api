<?php

use App\Agents\Commands;
use App\Ai\Assistant;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\HouseholdRole;
use App\Models\AgentCommand;
use App\Models\AiPendingAction;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);

    $this->client = AgentClient::enrolled($this, $this->home, [
        'inventory' => ['hostname' => 'MINIPC', 'os' => ['name' => 'Windows'], 'disks' => [], 'audio' => [], 'commands' => [
            ['key' => 'ping_router', 'description' => 'Ping a device', 'sensitive' => false,
                'params' => [['name' => 'host', 'description' => null, 'pattern' => '^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$']]],
            ['key' => 'restart_computer', 'description' => 'Restart', 'sensitive' => true, 'params' => []],
        ]],
    ]);
    $this->agent = $this->client->agent;
    $this->commands = app(Commands::class);
});

function beat(AgentClient $client): array
{
    return $client->post('heartbeat', ['version' => '0.5.0', 'platform' => 'windows-x64'])->assertOk()->json('commands');
}

it('queues an allowlisted command and hands it over once', function () {
    $command = $this->commands->request($this->agent, $this->member, 'ping_router', ['host' => '192.168.1.1']);
    expect($command->status)->toBe('queued')->and($command->household_id)->toBe($this->home->id);

    $sent = beat($this->client);
    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toMatchArray(['id' => $command->id, 'key' => 'ping_router', 'params' => ['host' => '192.168.1.1'], 'sensitive' => false, 'confirmed' => false])
        ->and($command->fresh()->status)->toBe('sent');

    expect(beat($this->client))->toBe([]);  // not again
});

it('records the result once, ignoring resends and other agents', function () {
    $command = $this->commands->request($this->agent, $this->member, 'ping_router', ['host' => '192.168.1.1']);
    beat($this->client);

    $result = ['status' => 'succeeded', 'exit_code' => 0, 'output' => "Reply from 192.168.1.1\n", 'truncated' => false, 'duration_ms' => 3021, 'started_at' => '2026-11-12T10:00:00Z', 'reason' => null];
    $this->client->post("commands/{$command->id}/result", $result)->assertNoContent();
    $this->client->post("commands/{$command->id}/result", ['status' => 'failed'] + $result)->assertNoContent();

    $command->refresh();
    expect($command->status)->toBe('succeeded')
        ->and($command->exit_code)->toBe(0)
        ->and($command->output)->toContain('Reply from')
        ->and($command->duration_ms)->toBe(3021)
        ->and($command->finished_at)->not->toBeNull();

    // Another computer can't report on it
    $other = AgentClient::enrolled($this, $this->home, ['name' => 'Laptop']);
    $other->post("commands/{$command->id}/result", ['status' => 'failed'] + $result)->assertNoContent();
    expect($command->fresh()->status)->toBe('succeeded');
});

it('refuses what is not on the allowlist or does not match', function () {
    expect(fn () => $this->commands->request($this->agent, $this->member, 'format_disk', []))
        ->toThrow(ValidationException::class);

    foreach (['192.168.1.1; calc', '-t', '10.0.0.1', "192.168.1.1\n"] as $value) {
        expect(fn () => $this->commands->request($this->agent, $this->member, 'ping_router', ['host' => $value]))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => $this->commands->request($this->agent, $this->member, 'ping_router', ['host' => '192.168.1.1', 'count' => '99']))
        ->toThrow(ValidationException::class);
    expect(AgentCommand::count())->toBe(0);
});

it('holds a sensitive command until the owner confirms it', function () {
    $command = $this->commands->request($this->agent, $this->member, 'restart_computer', []);
    expect($command->status)->toBe('awaiting_confirmation')
        ->and(beat($this->client))->toBe([]);

    expect(fn () => $this->commands->confirm($command, $this->member))->toThrow(ValidationException::class);

    $this->commands->confirm($command, $this->owner);
    $sent = beat($this->client);
    expect($sent[0])->toMatchArray(['key' => 'restart_computer', 'sensitive' => true, 'confirmed' => true])
        ->and($command->fresh()->confirmed_by)->toBe($this->owner->id);
});

it('expires what the computer never picked up', function () {
    $command = $this->commands->request($this->agent, $this->member, 'ping_router', ['host' => '192.168.1.1']);
    $waiting = $this->commands->request($this->agent, $this->member, 'restart_computer', []);
    $this->travel(11)->minutes();

    expect(beat($this->client))->toBe([])
        ->and($command->fresh()->status)->toBe('expired')
        ->and($waiting->fresh()->status)->toBe('expired');
    expect(fn () => $this->commands->confirm($waiting->fresh(), $this->owner))->toThrow(ValidationException::class);
});

it('can be cancelled before it is sent, by whoever asked or the owner', function () {
    $command = $this->commands->request($this->agent, $this->member, 'ping_router', ['host' => '192.168.1.1']);
    $this->commands->cancel($command, $this->member);
    expect($command->fresh()->status)->toBe('cancelled')->and(beat($this->client))->toBe([]);
});

it('runs nothing on a computer that was removed', function () {
    $this->agent->update(['revoked_at' => now()]);
    expect(fn () => $this->commands->request($this->agent->fresh(), $this->owner, 'ping_router', ['host' => '192.168.1.1']))
        ->toThrow(ValidationException::class);
});

describe('the assistant', function () {
    beforeEach(function () {
        $this->conversation = app(Assistant::class)->start($this->home, $this->member);
        $this->ctx = new ToolContext($this->home, $this->member, CarbonImmutable::now(), $this->conversation);
        $this->registry = app(ToolRegistry::class);
    });

    it('sees the computers, their readings, devices and commands', function () {
        $this->agent->forceFill(['metrics' => ['cpu_percent' => 12.5, 'temperatures' => [['label' => 'CPU', 'celsius' => 71]]]])->save();
        $out = $this->registry->call('get_computers', [], $this->ctx);

        expect($out->isError)->toBeFalse()
            ->and($out->content['computers'][0])->toMatchArray(['name' => 'Mini PC', 'online' => true, 'cpu_percent' => 12.5, 'hottest_celsius' => 71.0])
            ->and(collect($out->content['computers'][0]['commands'])->pluck('key')->all())->toBe(['ping_router', 'restart_computer']);
    });

    it('can only propose an allowlisted command, which the person confirms', function () {
        $out = $this->registry->call('run_computer_command', ['computer' => 'Mini PC', 'command' => 'ping_router', 'params' => ['host' => '192.168.1.1']], $this->ctx);
        expect($out->content['status'])->toBe('awaiting_confirmation')
            ->and(AgentCommand::count())->toBe(0);  // nothing queued until Confirm

        $outcome = $this->registry->confirm(AiPendingAction::findOrFail($out->pendingActionId), $this->ctx);
        expect($outcome->isError)->toBeFalse()
            ->and(AgentCommand::first())->source->toBe('assistant')
            ->and(AgentCommand::first()->status)->toBe('queued');
    });

    it('never runs a sensitive command, an unknown one, or something shaped like a shell', function () {
        foreach ([
            ['command' => 'restart_computer'],
            ['command' => 'format_disk'],
            ['command' => 'ping_router', 'params' => ['host' => '192.168.1.1 && shutdown /s']],
        ] as $input) {
            $out = $this->registry->call('run_computer_command', ['computer' => 'Mini PC'] + $input, $this->ctx);
            $outcome = $this->registry->confirm(AiPendingAction::findOrFail($out->pendingActionId), $this->ctx);
            expect($outcome->content)->toHaveKey('error');
        }
        // Not even a command key shaped like a shell line gets as far as a proposal
        expect($this->registry->call('run_computer_command', ['computer' => 'Mini PC', 'command' => 'cmd /c del'], $this->ctx)->isError)->toBeTrue();
        expect(AgentCommand::count())->toBe(0);
    });
});
