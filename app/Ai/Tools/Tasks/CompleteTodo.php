<?php

namespace App\Ai\Tools\Tasks;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Todo;
use Illuminate\Support\Facades\Gate;

class CompleteTodo extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'complete_todo';
    }

    public function label(): string
    {
        return 'Mark a to-do done';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Mark an open to-do as done. Takes the id from list_todos or get_today_plan.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['todo_id' => $this->idSchema('to-do')],
            'required' => ['todo_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['todo_id' => ['required', 'string', $this->idIn('todos', $context)->whereNull('done_at')]];
    }

    public function authorize(ToolContext $context): bool
    {
        return Gate::forUser($context->user)->allows('create', [Todo::class, $context->household]);
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Mark "'.$context->household->todos()->findOrFail((string) $input['todo_id'])->title.'" as done';
    }

    public function run(array $input, ToolContext $context): array
    {
        $todo = $context->household->todos()->findOrFail((string) $input['todo_id']);
        Gate::forUser($context->user)->authorize('update', $todo);
        $todo->update(['done_at' => now()]);

        return ['done' => $todo->title];
    }
}
