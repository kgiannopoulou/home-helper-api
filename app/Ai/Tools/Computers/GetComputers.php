<?php

namespace App\Ai\Tools\Computers;

use App\Agents\Heartbeats;
use App\Agents\Inventory;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentEvent;

/**
 * The household's computers running the local agent (agent/docs/design.md):
 * online or not, the latest readings, microphones and speakers, the commands
 * each one allows and the last few that ran.
 */
class GetComputers extends Tool
{
    public function name(): string
    {
        return 'get_computers';
    }

    public function label(): string
    {
        return 'Looked at the computers';
    }

    public function description(): string
    {
        return 'The household\'s computers running the Home Helper agent: whether each is online, when it was last seen, CPU, memory, the fullest disk, the hottest temperature, its microphones and speakers, what happened on it recently (disk low, running hot, offline, devices plugged in), the commands it allows (sensitive ones only the owner can run from the Computers page), and its recent commands with their results. Use it for questions like "is the mini PC running hot?", "how full is the disk?", "which microphones are plugged in?" or before proposing a command.';
    }

    public function run(array $input, ToolContext $context): array
    {
        $agents = $context->household->agents()->active()->orderBy('name')->get();

        return [
            'computers' => $agents->map(function (Agent $agent) {
                $metrics = $agent->metrics ?? [];

                return [
                    'name' => $agent->name,
                    'online' => $agent->isOnline(),
                    'last_seen' => $agent->last_seen_at?->toIso8601ZuluString(),
                    'os' => trim(($agent->inventory['os']['name'] ?? '').' '.($agent->inventory['os']['version'] ?? '')) ?: null,
                    'agent_version' => $agent->version,
                    'cpu_percent' => $metrics['cpu_percent'] ?? null,
                    'memory_percent' => Heartbeats::memoryPercent($metrics),
                    'fullest_disk_percent' => Heartbeats::fullestDisk($metrics),
                    'hottest_celsius' => Heartbeats::hottest($metrics),
                    'microphones' => array_map(fn ($d) => $d['name'].($d['default'] ? ' (default)' : '').($d['state'] !== 'active' ? " ({$d['state']})" : ''), Inventory::audio($agent, 'input')),
                    'speakers' => array_map(fn ($d) => $d['name'].($d['default'] ? ' (default)' : '').($d['state'] !== 'active' ? " ({$d['state']})" : ''), Inventory::audio($agent, 'output')),
                    'commands' => array_map(fn ($c) => [
                        'key' => $c['key'],
                        'description' => $c['description'],
                        'sensitive' => $c['sensitive'],
                        'params' => array_map(fn ($p) => ['name' => $p['name'], 'pattern' => $p['pattern'], 'description' => $p['description'] ?? null], $c['params']),
                    ], Inventory::commands($agent)),
                    'recent_events' => $agent->events()->orderByDesc('occurred_at')->limit(8)->get()
                        ->map(fn (AgentEvent $e) => ['type' => $e->type, 'severity' => $e->severity, 'message' => $e->message, 'at' => $e->occurred_at->toIso8601ZuluString()])->all(),
                    'recent_commands' => AgentCommand::query()->where('agent_id', $agent->id)->latest()->limit(5)->get()
                        ->map(fn (AgentCommand $c) => [
                            'command' => $c->label(),
                            'status' => $c->status,
                            'exit_code' => $c->exit_code,
                            'output' => $c->output !== null ? mb_substr($c->output, -600) : null,
                            'reason' => $c->reason,
                            'at' => $c->created_at->toIso8601ZuluString(),
                        ])->all(),
                ];
            })->all(),
        ];
    }
}
