<?php

namespace App\Ai\Tools\Finance;

use App\Ai\Tools\Concerns\HouseholdData;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Illuminate\Validation\Rule;

class ListExpenses extends Tool
{
    use HouseholdData;

    public function name(): string
    {
        return 'list_expenses';
    }

    public function label(): string
    {
        return 'Looked through the expenses';
    }

    public function description(): string
    {
        return 'Individual expenses between two dates (default the last 30 days), newest first or largest first, optionally one category. Use for "what did we spend on X", "biggest expenses", "when did we last pay Y".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'],
                'to' => ['type' => 'string', 'format' => 'date'],
                'category' => ['type' => 'string', 'enum' => ExpenseCategory::values()],
                'search' => ['type' => 'string', 'description' => 'Text in the note, e.g. "rent"'],
                'order' => ['type' => 'string', 'enum' => ['newest', 'largest']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category' => ['sometimes', Rule::enum(ExpenseCategory::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'order' => ['sometimes', 'in:newest,largest'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ];
    }

    public function run(array $input, ToolContext $context): array
    {
        $from = $input['from'] ?? $context->today->subDays(29)->toDateString();
        $to = $input['to'] ?? $context->today->toDateString();

        $query = $context->household->expenses()->getQuery()
            ->whereBetween('date', [$from, $to])
            ->when($input['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($input['search'] ?? null, fn ($q, $s) => $q->where('note', 'like', '%'.addcslashes($s, '%_\\').'%'));

        $sum = (float) (clone $query)->sum('amount');
        $count = (clone $query)->count();

        $rows = $query
            ->when(($input['order'] ?? 'newest') === 'largest', fn ($q) => $q->orderByDesc('amount'), fn ($q) => $q->orderByDesc('date'))
            ->orderBy('id')->limit($input['limit'] ?? 20)->with('user:id,name')->get()
            ->map(fn (Expense $e) => [
                'date' => $e->date->toDateString(),
                'amount' => $this->money($e->amount),
                'category' => $e->category->value,
                'note' => $e->note,
                'by' => $e->user?->name,
                'recurring_bill' => $e->recurring_bill_id !== null,
            ])->all();

        return ['from' => $from, 'to' => $to, 'currency' => $context->household->currency, 'count' => $count, 'sum' => $this->money($sum), 'expenses' => $rows];
    }
}
