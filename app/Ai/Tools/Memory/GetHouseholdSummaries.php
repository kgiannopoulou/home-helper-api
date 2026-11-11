<?php

namespace App\Ai\Tools\Memory;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\HouseholdSummary;

class GetHouseholdSummaries extends Tool
{
    public function name(): string
    {
        return 'get_household_summaries';
    }

    public function label(): string
    {
        return 'Read the weekly and monthly summaries';
    }

    public function description(): string
    {
        return 'The household\'s weekly (Monday to Sunday) and monthly summaries, written by Home Helper at the end of each period: money spent and the biggest categories with the change from the period before, chores done and by whom, to-dos, plant care, shopping trips, events and notes. Use it to compare periods ("did we spend more in October than September?") or for "how did last week go". Newest first.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => ['type' => 'string', 'enum' => ['week', 'month'], 'description' => 'Default month'],
                'from' => ['type' => 'string', 'format' => 'date', 'description' => 'Periods starting on or after this day'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Default 6'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'period' => ['sometimes', 'in:week,month'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'limit' => ['sometimes', 'integer', 'between:1,12'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $summaries = HouseholdSummary::query()->whereBelongsTo($context->household)
            ->where('period', $input['period'] ?? 'month')
            ->when($input['from'] ?? null, fn ($q, $from) => $q->where('starts_on', '>=', $from))
            ->orderByDesc('starts_on')->limit($input['limit'] ?? 6)->get();

        return [
            'summaries' => $summaries->map(fn (HouseholdSummary $s) => [
                'period' => $s->label(),
                'from' => $s->starts_on->toDateString(),
                'to' => $s->ends_on->toDateString(),
                'text' => $s->text,
                'spent' => $s->stats['spent'] ?? null,
                'spent_previous' => $s->stats['spent_previous'] ?? null,
            ])->all(),
            'note' => $summaries->isEmpty() ? 'No summaries yet: they are written at the end of each week and month. Use get_activity_timeline or get_spending_summary instead.' : null,
        ];
    }
}
