<?php

namespace App\Ai\Tools\Plants;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\AdminItem;
use App\Models\Supply;

/**
 * Keeping the home running: life-admin dates (renewals, appointments, bills)
 * and cleaning supplies that are running out.
 */
class ListHomeMaintenance extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'list_home_maintenance';
    }

    public function label(): string
    {
        return 'Checked home maintenance';
    }

    public function description(): string
    {
        return 'Home upkeep: life-admin items due in the next N days (default 60) or overdue — renewals, appointments, bills, with repeat intervals — and household supplies (cleaning, bathroom) that are low or out.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Default 60']],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['days' => ['sometimes', 'integer', 'between:1,365']];
    }

    public function run(array $input, ToolContext $context): array
    {
        $until = $context->today->addDays($input['days'] ?? 60)->toDateString();

        return [
            'admin' => AdminItem::query()->whereBelongsTo($context->household)->whereNull('done_at')
                ->whereNotNull('due_on')->where('due_on', '<=', $until)->orderBy('due_on')->limit(30)->get()
                ->map(fn (AdminItem $a) => [
                    'title' => $a->title,
                    'kind' => $a->kind->value,
                    'due_on' => $a->due_on->toDateString(),
                    'overdue' => $a->due_on->lt($context->today),
                    'repeats_every_months' => $a->repeat_months,
                    'amount' => $this->money($a->amount),
                ])->all(),
            'supplies_low_or_out' => Supply::query()->whereBelongsTo($context->household)
                ->whereIn('level', ['low', 'out'])->orderBy('name')->get()
                ->map(fn (Supply $s) => ['name' => $s->name, 'category' => $s->category->value, 'level' => $s->level->value])
                ->all(),
        ];
    }
}
