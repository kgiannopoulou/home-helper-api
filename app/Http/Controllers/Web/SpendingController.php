<?php

namespace App\Http\Controllers\Web;

use App\Enums\ExpenseCategory;
use App\Queries\BudgetForecastQuery;
use App\Queries\WeeklySpendingQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SpendingController extends WebController
{
    public function __invoke(Request $request): Response
    {
        $household = $this->household($request);
        $today = $this->today();
        $weeks = (int) ($request->validate(['weeks' => ['sometimes', 'integer', 'in:4,8,12,26']])['weeks'] ?? 8);

        return Inertia::render('spending', [
            'weeks' => $weeks,
            // Every Monday in range, so a week without spending still gets its (empty) bar
            'weekStarts' => array_map(fn (int $i) => $today->startOfWeek()->subWeeks($weeks - 1 - $i)->toDateString(), range(0, $weeks - 1)),
            'spending' => (new WeeklySpendingQuery($household, $today, $weeks))->get(),
            'forecast' => (new BudgetForecastQuery($household, $today))->get(),
            'categories' => ExpenseCategory::values(),
        ]);
    }
}
