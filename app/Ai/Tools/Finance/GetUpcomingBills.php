<?php

namespace App\Ai\Tools\Finance;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Models\AdminItem;
use App\Models\RecurringBill;

class GetUpcomingBills extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'get_upcoming_bills';
    }

    public function label(): string
    {
        return 'Checked the bills';
    }

    public function description(): string
    {
        return 'Bills coming up in the next N days (default 30): recurring bills (rent, power…) on their day of the month, whether this month\'s is already recorded, and one-off bills and renewals from life admin, with amounts and the total.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'description' => 'Default 30']],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['days' => ['sometimes', 'integer', 'between:1,90']];
    }

    public function run(array $input, ToolContext $context): array
    {
        $today = $context->today;
        $until = $today->addDays($input['days'] ?? 30);

        $recurring = [];
        foreach (RecurringBill::query()->whereBelongsTo($context->household)->where('active', true)->with('expenses')->orderBy('day_of_month')->get() as $bill) {
            // Its next date: this month if the day hasn't passed, else next month (a 31 in a short month → its last day)
            $month = $today->startOfMonth();
            $due = $month->setDay(min($bill->day_of_month, $month->daysInMonth));
            if ($due->lt($today)) {
                $month = $month->addMonth();
                $due = $month->setDay(min($bill->day_of_month, $month->daysInMonth));
            }
            if ($due->gt($until)) {
                continue;
            }
            $recurring[] = [
                'name' => $bill->name,
                'amount' => $this->money($bill->amount),
                'category' => $bill->category->value,
                'due_on' => $due->toDateString(),
                'already_recorded_this_month' => $bill->expenses->contains(fn ($e) => $e->date->isSameMonth($due)),
            ];
        }
        usort($recurring, fn ($a, $b) => $a['due_on'] <=> $b['due_on']);

        $admin = AdminItem::query()->whereBelongsTo($context->household)->whereNull('done_at')
            ->whereIn('kind', ['bill', 'renewal'])->whereNotNull('due_on')
            ->where('due_on', '<=', $until->toDateString())->orderBy('due_on')->get()
            ->map(fn (AdminItem $a) => [
                'title' => $a->title,
                'kind' => $a->kind->value,
                'amount' => $this->money($a->amount),
                'due_on' => $a->due_on->toDateString(),
                'overdue' => $a->due_on->lt($today),
            ])->all();

        $total = array_sum(array_column($recurring, 'amount')) + array_sum(array_map(fn ($a) => $a['amount'] ?? 0, $admin));

        return [
            'from' => $today->toDateString(),
            'to' => $until->toDateString(),
            'currency' => $context->household->currency,
            'recurring' => $recurring,
            'one_off_and_renewals' => $admin,
            'total' => $this->money($total),
        ];
    }
}
