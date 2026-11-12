<?php

namespace App\Console\Commands;

use App\Agents\AgentEvents;
use App\Agents\Commands;
use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\AgentEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * The Computers page from a terminal, for a headless server and for
 * agent/scripts/e2e.sh:
 *
 *   php artisan agent:admin list [--json]
 *   php artisan agent:admin show {agent} [--json]      readings, last commands and events
 *   php artisan agent:admin run {agent} {key} [name=value…] --email=…
 *   php artisan agent:admin confirm {command} --email=…
 *   php artisan agent:admin revoke {agent}
 *
 * {agent} is an id or a name. The same rules apply as on the page: the
 * allowlist, the parameters, and the owner for sensitive commands.
 */
class AgentAdmin extends Command
{
    protected $signature = 'agent:admin
        {action : list, show, run, confirm or revoke}
        {target? : the computer (id or name), or the command id for confirm}
        {key? : the command key, for run}
        {params?* : name=value, for run}
        {--email=demo@homehelper.test : who is asking (run, confirm)}
        {--json : machine-readable}';

    protected $description = 'List, inspect and command the household computers running the agent';

    public function handle(Commands $commands, AgentEvents $events): int
    {
        try {
            return match ($this->argument('action')) {
                'list' => $this->listAgents(),
                'show' => $this->showAgent($this->agent()),
                'run' => $this->queueCommand($commands),
                'confirm' => $this->confirmCommand($commands),
                'revoke' => $this->revokeAgent($events),
                default => $this->usage('list, show, run, confirm or revoke'),
            };
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }
    }

    private function listAgents(): int
    {
        $rows = Agent::query()->with('household:id,name')->orderBy('name')->get()->map(fn (Agent $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'household' => $a->household->name,
            'platform' => $a->platform,
            'version' => $a->version,
            'online' => $a->isOnline(),
            'revoked' => $a->isRevoked(),
            'last_seen_at' => $a->last_seen_at?->toIso8601ZuluString(),
        ]);
        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT));
        } else {
            $this->table(['id', 'name', 'household', 'platform', 'version', 'online', 'revoked', 'last seen'], $rows->map(fn ($r) => array_map(
                fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, $r)));
        }

        return self::SUCCESS;
    }

    private function showAgent(Agent $agent): int
    {
        $data = [
            'id' => $agent->id,
            'name' => $agent->name,
            'version' => $agent->version,
            'online' => $agent->isOnline(),
            'revoked' => $agent->isRevoked(),
            'metrics' => $agent->metrics,
            'inventory' => $agent->inventory,
            'outbox_pending' => $agent->outbox_pending,
            'commands' => AgentCommand::query()->where('agent_id', $agent->id)->latest()->limit(10)->get()
                ->map(fn (AgentCommand $c) => ['id' => $c->id, 'key' => $c->command_key, 'status' => $c->status, 'exit_code' => $c->exit_code, 'output' => $c->output, 'reason' => $c->reason])->all(),
            'events' => AgentEvent::query()->where('agent_id', $agent->id)->orderByDesc('occurred_at')->limit(30)->get()
                ->map(fn (AgentEvent $e) => ['type' => $e->type, 'severity' => $e->severity, 'message' => $e->message])->all(),
        ];
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return self::SUCCESS;
    }

    private function queueCommand(Commands $commands): int
    {
        $params = [];
        foreach ((array) $this->argument('params') as $pair) {
            [$name, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $params[$name] = $value;
        }
        $command = $commands->request($this->agent(), $this->user(), (string) $this->argument('key'), $params);
        $this->option('json') ? $this->line((string) json_encode(['id' => $command->id, 'status' => $command->status])) : $this->info("{$command->id} {$command->status}");

        return self::SUCCESS;
    }

    private function confirmCommand(Commands $commands): int
    {
        $command = $commands->confirm(AgentCommand::findOrFail($this->argument('target')), $this->user());
        $this->info("{$command->id} {$command->status}");

        return self::SUCCESS;
    }

    private function revokeAgent(AgentEvents $events): int
    {
        $agent = $this->agent();
        $agent->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        $events->record($agent, 'agent.revoked', 'info', $agent->name.' was removed from the command line');
        $this->info("{$agent->name} removed; its agent stops at its next heartbeat.");

        return self::SUCCESS;
    }

    private function agent(): Agent
    {
        $target = (string) $this->argument('target');
        $agent = Agent::query()->whereKey(strtolower($target))->first() ?? Agent::query()->where('name', $target)->latest()->first();
        if (! $agent) {
            throw ValidationException::withMessages(['agent' => "No computer \"{$target}\"."]);
        }

        return $agent;
    }

    private function user(): User
    {
        return User::query()->where('email', $this->option('email'))->firstOrFail();
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
