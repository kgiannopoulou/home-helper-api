<?php

namespace App\Ai\Tools;

use App\Models\AiPendingAction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Every tool the assistant can call, and the gate in front of them
 * (docs/assistant.md#permissions). For each call, in order:
 *
 *   1. the tool exists
 *   2. the person is a member of the household (checked again on every call)
 *   3. writes are allowed (homehelper.ai.allow_writes) and the tool's own permission holds
 *   4. the input passes the tool's validation rules (ids must be in this household)
 *   5. a read runs now; a write becomes a pending action that runs only on Confirm
 *
 * Anything that fails comes back to the model as an error result it can
 * explain or correct, never as an exception.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param  iterable<Tool>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
        ksort($this->tools); // a stable order keeps the prompt cache valid
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->tools;
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * The definitions sent to the model. Write tools are left out entirely when
     * writes are off, so the model doesn't offer what it can't do.
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'name' => $t->name(),
            'description' => $t->description().($t->writes() ? ' Changes data: the person must confirm it before it happens.' : ''),
            'input_schema' => $t->schema(),
        ], array_filter($this->tools, fn (Tool $t) => ! $t->writes() || config('homehelper.ai.allow_writes'))));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function call(string $name, array $input, ToolContext $context): ToolOutcome
    {
        $tool = $this->find($name);
        if (! $tool) {
            return ToolOutcome::error("There is no tool called {$name}.", ['available' => array_keys($this->tools)]);
        }

        if ($denied = $this->deny($tool, $context)) {
            return $denied;
        }

        $validator = Validator::make($input, $tool->rules($context));
        if ($validator->fails()) {
            return ToolOutcome::error('The input is not valid.', ['fields' => $validator->errors()->toArray()]);
        }
        $input = $validator->validated();

        if ($tool->writes()) {
            return $this->propose($tool, $input, $context);
        }

        return $this->run($tool, $input, $context);
    }

    /**
     * The person pressed Confirm: check everything again (they may have left
     * the household, the item may be gone), then do it.
     */
    public function confirm(AiPendingAction $action, ToolContext $context): ToolOutcome
    {
        $tool = $this->find($action->tool);
        if (! $tool) {
            return ToolOutcome::error("The tool {$action->tool} no longer exists.");
        }
        if ($denied = $this->deny($tool, $context)) {
            return $denied;
        }

        $validator = Validator::make($action->input, $tool->rules($context));
        if ($validator->fails()) {
            return ToolOutcome::error('This can no longer be done.', ['fields' => $validator->errors()->toArray()]);
        }

        return DB::transaction(fn () => $this->run($tool, $validator->validated(), $context));
    }

    private function deny(Tool $tool, ToolContext $context): ?ToolOutcome
    {
        if (! $context->user->isMemberOf($context->household)) {
            return ToolOutcome::error('You are not a member of this household.');
        }
        if ($tool->writes() && ! config('homehelper.ai.allow_writes')) {
            return ToolOutcome::error('The assistant may only read in this household.');
        }
        if (! $tool->authorize($context)) {
            return ToolOutcome::error('You are not allowed to do that.');
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function propose(Tool $tool, array $input, ToolContext $context): ToolOutcome
    {
        if (! $context->conversation) {
            return ToolOutcome::error('Changes can only be proposed inside a conversation.');
        }

        $action = AiPendingAction::create([
            'conversation_id' => $context->conversation->id,
            'user_id' => $context->user->id,
            'tool' => $tool->name(),
            'input' => $input,
            'summary' => mb_substr($tool->describe($input, $context), 0, 300),
            'expires_at' => CarbonImmutable::now()->addMinutes((int) config('homehelper.ai.confirmation_minutes')),
        ]);

        return new ToolOutcome([
            'status' => 'awaiting_confirmation',
            'action_id' => $action->id,
            'proposed' => $action->summary,
            'note' => 'Nothing has changed yet. Tell the person what you propose in one sentence; they confirm or cancel with the buttons under your reply. Do not say it is done.',
        ], pendingActionId: $action->id);
    }

    /** @param array<string, mixed> $input */
    private function run(Tool $tool, array $input, ToolContext $context): ToolOutcome
    {
        try {
            return new ToolOutcome($tool->run($input, $context));
        } catch (Throwable $e) {
            report($e);
            Log::warning('Assistant tool failed', ['tool' => $tool->name(), 'error' => $e->getMessage()]);

            return ToolOutcome::error('The tool failed. Say so, and do not guess the answer.');
        }
    }
}
