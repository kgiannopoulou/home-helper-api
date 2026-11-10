<?php

namespace App\Ai\Tools\Tasks;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Todo;
use Illuminate\Support\Facades\Gate;

class AddTodo extends Tool
{
    public function name(): string
    {
        return 'add_todo';
    }

    public function label(): string
    {
        return 'Add a to-do';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Add a to-do for the household, optionally with a due date (YYYY-MM-DD, at home; work out "tomorrow" or "Friday" from today\'s date in the context).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'due_on' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD, optional'],
                'minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1440, 'description' => 'How long it takes, optional'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$context->today->subYear()->toDateString()],
            'minutes' => ['nullable', 'integer', 'between:1,1440'],
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return Gate::forUser($context->user)->allows('create', [Todo::class, $context->household]);
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Add the to-do "'.$input['title'].'"'.(! empty($input['due_on']) ? ', due '.$input['due_on'] : '');
    }

    public function run(array $input, ToolContext $context): array
    {
        $todo = $context->household->todos()->create([
            'user_id' => $context->user->id,
            'title' => $input['title'],
            'due_on' => $input['due_on'] ?? null,
            'minutes' => $input['minutes'] ?? null,
        ]);

        return ['added' => ['id' => $todo->id, 'title' => $todo->title, 'due_on' => $todo->due_on?->toDateString()]];
    }
}
