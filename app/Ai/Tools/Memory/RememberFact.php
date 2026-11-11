<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\FactKind;
use Illuminate\Validation\Rule;

/**
 * The assistant proposes a fact; the person confirms it (like every write).
 */
class RememberFact extends Tool
{
    public function name(): string
    {
        return 'remember_fact';
    }

    public function label(): string
    {
        return 'Remember something';
    }

    public function writes(): bool
    {
        return true;
    }

    public function description(): string
    {
        return 'Remember something lasting about the household, for every later conversation: a preference ("Alex doesn\'t eat meat"), a routine ("bins go out on Tuesday night") or a fact ("the boiler was serviced in March 2026"). One short sentence in the third person, with dates as dates. about: the member it is about, by name, if any. private: only this person will see it. Only what the person said; never passwords, codes or health details unless they ask.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => FactKind::values()],
                'content' => ['type' => 'string', 'description' => 'One sentence, at most 300 characters'],
                'about' => ['type' => 'string', 'description' => 'A member\'s name, optional'],
                'private' => ['type' => 'boolean', 'description' => 'Default false: the whole household sees it'],
            ],
            'required' => ['kind', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'kind' => ['required', Rule::enum(FactKind::class)],
            'content' => ['required', 'string', 'min:3', 'max:300'],
            'about' => ['sometimes', 'nullable', 'string', Rule::in($context->household->users()->pluck('name')->all())],
            'private' => ['sometimes', 'boolean'],
        ];
    }

    public function describe(array $input, ToolContext $context): string
    {
        return 'Remember: "'.$input['content'].'"'.(! empty($input['private']) ? ' (only for you)' : '');
    }

    public function run(array $input, ToolContext $context): array
    {
        $fact = $context->household->facts()->create([
            'user_id' => $context->user->id,
            'about_user_id' => isset($input['about']) ? $context->household->users()->where('name', $input['about'])->value('users.id') : null,
            'kind' => $input['kind'],
            'content' => $input['content'],
            'source' => 'assistant',
            'private' => (bool) ($input['private'] ?? false),
        ]);

        return ['remembered' => ['id' => $fact->id, 'line' => $fact->load('about')->line()]];
    }
}
