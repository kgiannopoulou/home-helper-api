<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AgentCommand;
use App\Models\User;
use App\Policies\AgentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Asking a computer to do something (agent/docs/commands.md).
 *
 * There is no "run this" here: a request names a key from the computer's own
 * allowlist (its inventory) and fills its declared parameters. The server
 * checks what it can (the key exists, the parameters match their patterns),
 * but the agent checks everything again and has the last word.
 *
 * Sensitive commands wait for the household owner to press Confirm, and the
 * agent refuses one that arrives unconfirmed. The assistant can't ask for a
 * sensitive command at all (App\Ai\Tools\Computers\RunComputerCommand).
 */
class Commands
{
    /**
     * @param  array<string, mixed>  $params
     *
     * @throws ValidationException
     */
    public function request(Agent $agent, User $user, string $key, array $params, string $source = 'dashboard'): AgentCommand
    {
        if (! (new AgentPolicy)->run($user, $agent)) {
            throw ValidationException::withMessages(['agent' => 'You can\'t run commands on this computer.']);
        }
        if (! $agent->isOnline() && $agent->last_seen_at === null) {
            throw ValidationException::withMessages(['agent' => 'This computer has never checked in.']);
        }

        $spec = Inventory::command($agent, $key);
        if (! $spec) {
            throw ValidationException::withMessages(['command' => "\"{$key}\" is not on this computer's allowlist."]);
        }
        $params = $this->checkParams($spec, $params);

        $command = new AgentCommand([
            'agent_id' => $agent->id,
            'requested_by' => $user->id,
            'source' => $source,
            'command_key' => $key,
            'params' => $params,
            'sensitive' => $spec['sensitive'],
            'status' => $spec['sensitive'] ? 'awaiting_confirmation' : 'queued',
            'expires_at' => CarbonImmutable::now()->addMinutes((int) config('homehelper.agents.command_minutes')),
        ]);
        $command->household_id = $agent->household_id;
        $command->save();

        Log::info('Agent command requested', ['command_id' => $command->id, 'agent_id' => $agent->id, 'key' => $key, 'user_id' => $user->id, 'source' => $source, 'status' => $command->status]);

        return $command;
    }

    /** The owner pressed Confirm on a sensitive command */
    public function confirm(AgentCommand $command, User $user): AgentCommand
    {
        if (! (new AgentPolicy)->confirm($user, $command->agent)) {
            throw ValidationException::withMessages(['command' => 'Only the household owner can confirm this.']);
        }

        return DB::transaction(function () use ($command, $user) {
            $command = AgentCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($command->status !== 'awaiting_confirmation' || $command->expires_at->isPast()) {
                throw ValidationException::withMessages(['command' => 'This command is no longer waiting for confirmation.']);
            }
            $command->forceFill([
                'status' => 'queued',
                'confirmed_by' => $user->id,
                'confirmed_at' => CarbonImmutable::now(),
                // A fresh window from the confirmation, not from the request
                'expires_at' => CarbonImmutable::now()->addMinutes((int) config('homehelper.agents.command_minutes')),
            ])->save();
            Log::info('Agent command confirmed', ['command_id' => $command->id, 'user_id' => $user->id]);

            return $command;
        });
    }

    /** Before it was sent: the person who asked, or the owner */
    public function cancel(AgentCommand $command, User $user): AgentCommand
    {
        $mayCancel = $command->requested_by === $user->id || (new AgentPolicy)->manage($user, $command->agent);
        if (! $mayCancel || ! in_array($command->status, ['awaiting_confirmation', 'queued'], true)) {
            throw ValidationException::withMessages(['command' => 'This command can\'t be cancelled now.']);
        }
        $command->forceFill(['status' => 'cancelled', 'finished_at' => CarbonImmutable::now()])->save();

        return $command;
    }

    /**
     * The commands to hand over in this heartbeat's reply. Each is sent once:
     * it becomes `sent`, and only a result moves it on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forHeartbeat(Agent $agent): array
    {
        $now = CarbonImmutable::now();

        AgentCommand::query()
            ->where('agent_id', $agent->id)
            ->whereIn('status', ['awaiting_confirmation', 'queued'])
            ->where('expires_at', '<', $now)
            ->update(['status' => 'expired', 'finished_at' => $now, 'updated_at' => $now]);

        return DB::transaction(function () use ($agent, $now) {
            $queued = AgentCommand::query()
                ->where('agent_id', $agent->id)
                ->where('status', 'queued')
                ->orderBy('created_at')
                ->limit(10)
                ->lockForUpdate()
                ->get();

            foreach ($queued as $command) {
                $command->forceFill(['status' => 'sent', 'sent_at' => $now])->save();
            }

            return $queued->map(fn (AgentCommand $c) => [
                'id' => $c->id,
                'key' => $c->command_key,
                'params' => (object) $c->params,
                'sensitive' => $c->sensitive,
                'confirmed' => $c->confirmed_by !== null,
                'expires_at' => $c->expires_at->toIso8601ZuluString('millisecond'),
            ])->values()->all();
        });
    }

    /**
     * The agent's result. Ignored unless the command is this agent's and was
     * sent to it, so a resend after a lost reply changes nothing.
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function recordResult(Agent $agent, string $commandId, array $data): void
    {
        $command = AgentCommand::query()->where('agent_id', $agent->id)->find($commandId);
        if (! $command || $command->status !== 'sent') {
            return;
        }
        $limit = (int) config('homehelper.agents.output_kb') * 1024;
        $output = (string) ($data['output'] ?? '');

        $command->forceFill([
            'status' => $data['status'],
            'exit_code' => $data['exit_code'] ?? null,
            'output' => mb_strcut($output, 0, $limit),
            'truncated' => ($data['truncated'] ?? false) || strlen($output) > $limit,
            'duration_ms' => $data['duration_ms'] ?? null,
            'reason' => isset($data['reason']) ? mb_substr((string) $data['reason'], 0, 500) : null,
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        Log::info('Agent command finished', ['command_id' => $command->id, 'agent_id' => $agent->id, 'status' => $command->status, 'exit_code' => $command->exit_code]);
    }

    /**
     * The same checks the agent makes on each value, so a mistake is caught
     * on the dashboard instead of coming back as "rejected" a heartbeat later.
     *
     * @param  array{params: list<array{name: string, pattern: string}>}  $spec
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function checkParams(array $spec, array $params): array
    {
        $declared = collect($spec['params'])->keyBy('name');
        $errors = [];
        $clean = [];

        foreach (array_keys($params) as $name) {
            if (! $declared->has($name)) {
                $errors["params.{$name}"] = "\"{$name}\" is not a parameter of this command.";
            }
        }
        foreach ($declared as $name => $param) {
            $value = $params[$name] ?? null;
            if (! is_string($value) || $value === '') {
                $errors["params.{$name}"] = "\"{$name}\" is required.";

                continue;
            }
            if (mb_strlen($value) > 200 || str_starts_with($value, '-') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $errors["params.{$name}"] = "\"{$name}\" is not an allowed value.";

                continue;
            }
            // The agent uses ECMAScript regular expressions; for the simple patterns an
            // allowlist uses, PCRE agrees. If it can't compile one, the agent still checks.
            $matched = @preg_match('~'.str_replace('~', '\~', $param['pattern']).'~u', $value);
            if ($matched === 0) {
                $errors["params.{$name}"] = "\"{$name}\" must match {$param['pattern']}.";

                continue;
            }
            $clean[$name] = $value;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
