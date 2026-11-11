<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Queries\TimelineQuery;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * What happened, from every module (docs/memory.md#activity-timeline).
 */
class GetActivityTimeline extends Tool
{
    public function name(): string
    {
        return 'get_activity_timeline';
    }

    public function label(): string
    {
        return 'Looked at the household\'s history';
    }

    public function description(): string
    {
        return 'The household\'s history, newest first: expenses, shopping trips, chores done, to-dos done, plant care (watered, fertilised, repotted…), calendar events, bills and renewals dealt with, notes and facts added, and changes made through you. Use it for "when did we last…", "who watered the Monstera", "what did we do last weekend", "how much did we spend at the market in October". Filter by kinds and a search word (matches titles and details, e.g. a plant, chore, store or category name). Defaults to the last 30 days; search years back with from.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD, default 30 days ago'],
                'to' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD, default today'],
                'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => TimelineQuery::kinds()]],
                'search' => ['type' => 'string', 'description' => 'One word or name to match, e.g. "Monstera" or "oven"'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 30'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.$context->today->subYears(5)->toDateString()],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kinds' => ['sometimes', 'array'],
            'kinds.*' => [Rule::in(TimelineQuery::kinds())],
            'search' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from']) : $context->today->subDays(30);
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to']) : $context->today;
        $limit = $input['limit'] ?? 30;

        $rows = (new TimelineQuery($context->household, $context->today, $context->user, $from, $to,
            $input['kinds'] ?? null, $input['search'] ?? null, $limit + 1))->get();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $context->household->currency,
            'entries' => array_map(fn (array $r) => array_diff_key($r, ['id' => true]), array_slice($rows, 0, $limit)),
            'more_than_shown' => count($rows) > $limit,
        ];
    }
}
