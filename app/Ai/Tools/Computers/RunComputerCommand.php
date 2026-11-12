<?php

namespace App\Ai\Tools\Computers;

use App\Agents\Commands;
use App\Agents\Inventory;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Agent;
use App\Policies\AgentPolicy;
use Illuminate\Validation\ValidationException;

/**
 * Proposes one command from a computer's own allowlist. The assistant never
 * gets a shell (agent/docs/design.md#security-decisions):
 *
 *   - it names a key the computer allows, with values for its declared
 *     parameters; there is nothing else it can ask for
 *   - like every write, the person confirms it before anything is queued
 *   - sensitive commands are refused outright: only the household owner can
 *     run those, from the Computers page
 *   - the agent checks the key and every value again before it runs anything
 */
class RunComputerCommand extends Tool
{
    public function __construct(private Commands $commands) {}

    public function name(): string
    {
        return 'run_computer_command';
    }

    public function label(): string
    {
        return 'Run a command on a computer';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Ask one of the household\'s computers to run a command from its own allowlist (see get_computers for each computer\'s commands and their parameters). Only those exact commands exist; there is no shell and no way to run anything else. Sensitive commands can\'t be run from here: tell the person the owner can run them from the Computers page. The computer runs it at its next check-in (within about 30 seconds); look at get_computers afterwards for the result.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'computer' => ['type' => 'string', 'description' => 'The computer\'s name, as get_computers shows it'],
                'command' => ['type' => 'string', 'description' => 'The command key, e.g. ping_router'],
                'params' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'A value for each of the command\'s parameters'],
            ],
            'required' => ['computer', 'command'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'computer' => ['required', 'string', 'max:80'],
            'command' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/'],
            'params' => ['nullable', 'array', 'max:10'],
            'params.*' => ['string', 'max:200'],
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return (new AgentPolicy)->viewAny($context->user, $context->household);
    }

    public function describe(array $input, ToolContext $context): string
    {
        $pairs = [];
        foreach ((array) ($input['params'] ?? []) as $name => $value) {
            $pairs[] = "{$name}={$value}";
        }
        $params = implode(', ', $pairs);

        return 'Run "'.$input['command'].'"'.($params ? " ({$params})" : '').' on '.$input['computer'];
    }

    public function run(array $input, ToolContext $context): array
    {
        $agent = $context->household->agents()->active()->where('name', $input['computer'])->first();
        if (! $agent instanceof Agent) {
            return ['error' => 'There is no computer called "'.$input['computer'].'" in this household.'];
        }
        $spec = Inventory::command($agent, $input['command']);
        if (! $spec) {
            return ['error' => '"'.$input['command'].'" is not one of the commands this computer allows.'];
        }
        if ($spec['sensitive']) {
            return ['error' => '"'.$input['command'].'" is sensitive. Only the household owner can run it, from the Computers page.'];
        }

        try {
            $command = $this->commands->request($agent, $context->user, $input['command'], $input['params'] ?? [], 'assistant');
        } catch (ValidationException $e) {
            return ['error' => 'The computer would refuse this.', 'details' => $e->errors()];
        }

        return [
            'queued' => ['id' => $command->id, 'computer' => $agent->name, 'command' => $command->label()],
            'note' => $agent->isOnline()
                ? 'It runs at the computer\'s next check-in, within about '.config('homehelper.agents.heartbeat_seconds').' seconds.'
                : 'The computer is offline; it runs if it comes back within '.config('homehelper.agents.command_minutes').' minutes.',
        ];
    }
}
