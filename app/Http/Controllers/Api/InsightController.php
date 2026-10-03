<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Queries\BudgetForecastQuery;
use App\Queries\RunOutQuery;
use App\Queries\ShoppingDayQuery;
use App\Queries\SlippingChoresQuery;
use App\Queries\WeeklySpendingQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trends and predictions, worked out in MySQL (app/Queries). Membership is
 * checked by the route's can:view,household middleware.
 */
class InsightController extends Controller
{
    public function weeklySpending(Request $request, Household $household): JsonResponse
    {
        $weeks = (int) ($request->validate(['weeks' => ['sometimes', 'integer', 'between:1,52']])['weeks'] ?? 8);

        return response()->json(['data' => (new WeeklySpendingQuery($household, $this->today(), $weeks))->get()]);
    }

    public function runOut(Household $household): JsonResponse
    {
        return response()->json(['data' => (new RunOutQuery($household, $this->today()))->get()]);
    }

    public function slippingChores(Household $household): JsonResponse
    {
        return response()->json(['data' => (new SlippingChoresQuery($household, $this->today()))->get()]);
    }

    public function shoppingDay(Household $household): JsonResponse
    {
        return response()->json(['data' => (new ShoppingDayQuery($household, $this->today()))->get()]);
    }

    public function budgetForecast(Household $household): JsonResponse
    {
        return response()->json(['data' => (new BudgetForecastQuery($household, $this->today()))->get()]);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }
}
