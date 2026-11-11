<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\NoteKind;

class SaveNote extends Tool
{
    public function name(): string
    {
        return 'save_note';
    }

    public function label(): string
    {
        return 'Save a note';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Save a note in the household\'s notes, for something longer than a fact: a decision with its reasons, a plan, instructions someone dictated, a list of contacts. Write it as the person said it, with dates as dates. private: only this person sees it.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'At most 5,000 characters'],
                'private' => ['type' => 'boolean'],
            ],
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'private' => ['sometimes', 'boolean'],
        ];
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Save the note "'.$input['title'].'"'.(! empty($input['private']) ? ' (only for you)' : '');
    }

    public function run(array $input, ToolContext $context): array
    {
        $note = $context->household->notes()->create([
            'user_id' => $context->user->id,
            'kind' => NoteKind::Note,
            'title' => $input['title'],
            'body' => $input['body'],
            'private' => (bool) ($input['private'] ?? false),
        ]);

        return ['saved' => ['note_id' => $note->id, 'title' => $note->title]];
    }
}
