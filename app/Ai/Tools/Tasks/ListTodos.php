<?php

namespace App\Ai\Tools\Tasks;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Todo;

class ListTodos extends Tool
{
    public function name(): string
    {
        return 'list_todos';
    }

    public function label(): string
    {
        return 'Looked at the to-do list';
    }

    public function description(): string
    {
        return 'The household\'s to-dos. By default the open ones, overdue first, then by due date (undated last). Each has an id (for complete_todo), title, due date and whether it is overdue.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['open', 'done', 'all'], 'description' => 'Default open'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Default 20'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'status' => ['sometimes', 'in:open,done,all'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $status = $input['status'] ?? 'open';
        $query = $context->household->todos()->getQuery()
            ->when($status === 'open', fn ($q) => $q->whereNull('done_at'))
            ->when($status === 'done', fn ($q) => $q->whereNotNull('done_at'))
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('title');

        $total = (clone $query)->count();
        $todos = $query->limit($input['limit'] ?? 20)->get()->map(fn (Todo $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'due_on' => $t->due_on?->toDateString(),
            'overdue' => $t->done_at === null && $t->due_on !== null && $t->due_on->lt($context->today),
            'minutes' => $t->minutes,
            'done' => $t->done_at !== null,
        ])->all();

        return ['status' => $status, 'total' => $total, 'todos' => $todos];
    }
}
