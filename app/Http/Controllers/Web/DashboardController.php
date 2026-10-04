<?php

namespace App\Http\Controllers\Web;

use App\Queries\BudgetForecastQuery;
use App\Queries\OverdueChoresQuery;
use App\Queries\ShoppingDayQuery;
use App\Queries\WeeklySpendingQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends WebController
{
    public function __invoke(Request $request): Response
    {
        $household = $this->household($request);
        $today = $this->today();

        return Inertia::render('dashboard', [
            'forecast' => (new BudgetForecastQuery($household, $today))->get(),
            'spentThisWeek' => round(array_sum(array_column((new WeeklySpendingQuery($household, $today, 1))->get(), 'total')), 2),
            'shoppingDay' => (new ShoppingDayQuery($household, $today))->get()['usual'],
            'overdue' => array_slice((new OverdueChoresQuery($household, $today))->get(), 0, 5),
            'toBuy' => $household->shoppingItems()->where('checked', false)->count(),
        ]);
    }
}
