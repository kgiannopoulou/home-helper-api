<?php

namespace App\Ai\Tools\Tasks;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\ChoreCompletion;
use Illuminate\Support\Facades\Gate;

class MarkChoreDone extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'mark_chore_done';
    }

    public function label(): string
    {
        return 'Mark a chore done';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Record that a chore was done now, by the person asking. Takes the chore id from list_overdue_chores or get_today_plan.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chore_id' => $this->idSchema('chore'),
                'minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600, 'description' => 'How long it took; default the chore\'s usual time'],
            ],
            'required' => ['chore_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'chore_id' => ['required', 'string', $this->idIn('chores', $context)],
            'minutes' => ['nullable', 'integer', 'between:1,600'],
        ];
    }

    public function authorize(ToolContext $context): bool
    {
        return Gate::forUser($context->user)->allows('create', [ChoreCompletion::class, $context->household]);
    }

    public function describe(array $input, ToolContext $context): string
    {
        $chore = $context->household->chores()->with('room')->findOrFail((string) $input['chore_id']);

        return "Mark \"{$chore->room->name}: {$chore->name}\" as done now";
    }

    public function run(array $input, ToolContext $context): array
    {
        $chore = $context->household->chores()->findOrFail((string) $input['chore_id']);
        $context->household->choreCompletions()->create([
            'chore_id' => $chore->id,
            'user_id' => $context->user->id,
            'done_at' => now(),
            'minutes' => $input['minutes'] ?? $chore->minutes,
        ]);

        return ['done' => $chore->name, 'next_due_in_days' => $chore->every_days];
    }
}
