<?php

namespace App\Ai\Tools\Finance;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\BudgetPeriod;
use App\Models\Budget;
use App\Queries\BudgetForecastQuery;
use Carbon\CarbonImmutable;

/**
 * "How much did I spend this month?": totals by category, against the
 * monthly budgets, the month before, and (for the current month) where it is
 * heading.
 */
class GetSpendingSummary extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'get_spending_summary';
    }

    public function label(): string
    {
        return 'Added up the spending';
    }

    public function description(): string
    {
        return 'Household spending for one calendar month (default: this month, to today): the total, each category\'s total and its monthly budget, how much of that was recurring bills, the previous month\'s total for comparison, and for the current month a forecast of where it will end against the budget. Amounts are in the household currency.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['month' => ['type' => 'string', 'description' => 'YYYY-MM; default the current month']],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m', 'before_or_equal:'.$context->today->format('Y-m')]];
    }

    public function run(array $input, ToolContext $context): array
    {
        $h = $context->household;
        $start = isset($input['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $input['month'], $context->today->timezone)
            : $context->today->startOfMonth();
        $current = $start->isSameMonth($context->today);
        $end = $current ? $context->today : $start->endOfMonth()->startOfDay();

        $byCategory = $h->expenses()->getQuery()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category, sum(amount) as total, count(*) as expenses, sum(recurring_bill_id is not null) as from_bills')
            ->groupBy('category')->orderByDesc('total')
            ->toBase()->get(); // plain rows: category stays the stored string

        $budgets = $h->budgets()->where('period', BudgetPeriod::Month)->get()
            ->keyBy(fn (Budget $b): string => $b->category === null ? 'all' : $b->category->value);

        $previous = $start->subMonth();
        $total = (float) $byCategory->sum('total');

        return [
            'month' => $start->format('Y-m'),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'currency' => $h->currency,
            'total' => $this->money($total),
            'budget' => $this->money($budgets->get('all')?->amount),
            'categories' => $byCategory->map(fn (object $r) => [
                'category' => (string) $r->category,
                'total' => $this->money($r->total),
                'expenses' => (int) $r->expenses,
                'from_recurring_bills' => (int) $r->from_bills,
                'budget' => $this->money($budgets->get((string) $r->category)?->amount),
            ])->values()->all(),
            'previous_month_total' => $this->money($h->expenses()->whereBetween('date', [$previous->startOfMonth()->toDateString(), $previous->endOfMonth()->toDateString()])->sum('amount')),
            'forecast' => $current ? (new BudgetForecastQuery($h, $context->today))->get() : null,
        ];
    }
}
