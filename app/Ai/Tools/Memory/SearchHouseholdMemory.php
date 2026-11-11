<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\MemorySource;
use App\Memory\KnowledgeBase;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * The knowledge base, for the assistant (docs/memory.md#search).
 */
class SearchHouseholdMemory extends Tool
{
    public function __construct(private KnowledgeBase $knowledge) {}

    public function name(): string
    {
        return 'search_household_memory';
    }

    public function label(): string
    {
        return 'Searched the household\'s notes and memory';
    }

    public function description(): string
    {
        return 'Searches what the household has written down or told you over time: notes and uploaded documents (manuals, warranties, instructions, plans, decisions), remembered facts and preferences, summaries of earlier conversations with you, and weekly and monthly household summaries. Hybrid search (meaning and keywords). Use it for "what did we decide about…", "how do I reset the boiler", "what did I tell you about…", "how did October go". Each hit has its source, title, the passage, the date it is about and, for notes, a note_id for read_note. Write the query as the words likely to be in the passage, not as a question.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to look for, e.g. "boiler pressure reset"'],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MemorySource::values()], 'description' => 'Only these; default all'],
                'from' => ['type' => 'string', 'format' => 'date', 'description' => 'Only memory about this day or later (YYYY-MM-DD)'],
                'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Only memory about this day or earlier'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Default 6'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'query' => ['required', 'string', 'max:300'],
            'sources' => ['sometimes', 'array'],
            'sources.*' => [Rule::in(MemorySource::values())],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'limit' => ['sometimes', 'integer', 'between:1,10'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $hits = $this->knowledge->search(
            $context->household,
            $context->user,
            $input['query'],
            $input['limit'] ?? 6,
            isset($input['sources']) ? array_values(array_map(MemorySource::from(...), $input['sources'])) : null,
            isset($input['from']) ? CarbonImmutable::parse($input['from']) : null,
            isset($input['to']) ? CarbonImmutable::parse($input['to']) : null,
        );

        return [
            'query' => $input['query'],
            'hits' => array_map(fn (array $h) => [
                'source' => $h['source_type'],
                'title' => $h['title'],
                'passage' => mb_substr($h['text'], 0, 1200),
                'about_date' => $h['occurred_on'],
                'note_id' => in_array($h['source_type'], ['note', 'document'], true) ? $h['source_id'] : null,
                'matched_by' => $h['matched_by'],
            ], $hits),
            'note' => $hits === [] ? 'Nothing in the household\'s memory matches. Try other words, or get_activity_timeline for things that happened.' : null,
        ];
    }
}
