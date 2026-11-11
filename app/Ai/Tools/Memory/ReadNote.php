<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\Note;
use Illuminate\Validation\Rule;

class ReadNote extends Tool
{
    use HouseholdData;

    private const MAX_CHARS = 12000;

    public function name(): string
    {
        return 'read_note';
    }

    public function label(): string
    {
        return 'Read a note';
    }

    public function description(): string
    {
        return 'The whole text of one note or document, by the note_id from search_household_memory. Use it when a passage is not enough (step-by-step instructions, a long manual). Long documents are cut at 12,000 characters.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['note_id' => $this->idSchema('note')],
            'required' => ['note_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['note_id' => ['required', 'string', Rule::exists('notes', 'id')
            ->where('household_id', $context->household->id)
            ->where(fn ($q) => $q->where('private', false)->orWhere('user_id', $context->user->id))]];
    }

    public function run(array $input, ToolContext $context): array
    {
        $note = Note::query()->visibleTo($context->household, $context->user)->with('user:id,name')->whereKey($input['note_id'])->firstOrFail();

        return [
            'title' => $note->title,
            'kind' => $note->kind->value,
            'file_name' => $note->file_name,
            'added_by' => $note->user?->name,
            'updated' => $note->updated_at->toDateString(),
            'text' => mb_substr($note->body, 0, self::MAX_CHARS),
            'cut' => mb_strlen($note->body) > self::MAX_CHARS,
        ];
    }
}
