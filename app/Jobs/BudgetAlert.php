<?php

namespace App\Jobs;

use App\Models\Household;
use App\Models\JobRun;
use App\Push\ExpoPush;
use App\Queries\BudgetForecastQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Daily: when this month's forecast (the Phase 3 query) goes over the budget, tells
 * the household. It doesn't nag every evening: after one alert in a month, the next
 * comes only when the overshoot has grown by another 10% of the budget.
 */
class BudgetAlert extends HouseholdJob
{
    /** How much more over budget (share of the budget) before alerting again this month */
    public const STEP = 0.10;

    protected function run(Household $household, CarbonImmutable $today): array
    {
        $f = (new BudgetForecastQuery($household, $today))->get();
        $summary = ['forecast' => $f['forecast'], 'budget' => $f['budget'], 'over_budget' => $f['over_budget'], 'alerted' => false];

        if ($f['budget'] === null || $f['over_budget'] === null || $f['over_budget'] <= 0) {
            return $summary;
        }

        $lastAlert = $household->jobRuns()
            ->where('job', self::name())
            ->where('for_date', '>=', $today->startOfMonth()->toDateString())
            ->where('for_date', '<', $today->toDateString())
            ->where('summary->alerted', true)
            ->latest('started_at')
            ->first();
        $lastOver = $lastAlert instanceof JobRun ? (float) ($lastAlert->summary['over_budget'] ?? 0) : null;
        if ($lastOver !== null && $f['over_budget'] < $lastOver + $f['budget'] * self::STEP) {
            return [...$summary, 'quiet' => 'alerted already this month'];
        }

        $c = $household->currency;
        $push = app(ExpoPush::class)->toUsers(
            $household->users,
            '💸 Heading over budget',
            sprintf('At this pace: %s of %s this month, %s over. Spent %s so far.',
                Money::whole($f['forecast'], $c), Money::whole($f['budget'], $c), Money::whole($f['over_budget'], $c), Money::whole($f['spent'], $c)),
            ['url' => '/money'],
        );

        // Only counts as alerted once a phone got it, so a household that turns push on later still hears
        return [...$summary, 'alerted' => $push['sent'] > 0, 'push' => $push];
    }
}
