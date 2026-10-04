<?php

namespace App\Jobs;

use App\Mail\WeeklySummaryMail;
use App\Models\Chore;
use App\Models\Household;
use App\Push\ExpoPush;
use App\Queries\BudgetForecastQuery;
use App\Queries\NutritionGapsQuery;
use App\Queries\OverdueChoresQuery;
use App\Queries\WeeklySpendingQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Sunday evening: how the week went, by email and push to each member.
 * Budget pace and chores are the household's; nutrition gaps are personal, so
 * each member only ever sees their own.
 */
class WeeklySummary extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $shared = $this->shared($household, $today);
        $sent = [];

        foreach ($household->users as $user) {
            $nutrition = (new NutritionGapsQuery($household, $today, $user))->get();

            Mail::to($user)->send(new WeeklySummaryMail($household, $user, $today, $shared, $nutrition));
            $push = app(ExpoPush::class)->toUsers([$user], "📊 {$household->name}: your week", self::pushText($household, $shared, $nutrition), ['url' => '/']);

            $sent[] = ['user_id' => $user->id, 'gaps' => array_column($nutrition['gaps'], 'key'), 'push' => $push];
        }

        return [
            'spent_this_week' => $shared['spent_this_week'],
            'forecast' => $shared['budget']['forecast'],
            'overdue_chores' => count($shared['overdue']),
            'learned_chores' => count($shared['learned']),
            'members' => $sent,
        ];
    }

    /**
     * @return array{budget: array<string, mixed>, spent_this_week: float, overdue: array<int, array<string, mixed>>, learned: array<int, array{chore: string, from: int|null, to: int}>}
     */
    private function shared(Household $household, CarbonImmutable $today): array
    {
        $week = (new WeeklySpendingQuery($household, $today, 1))->get();

        return [
            'budget' => (new BudgetForecastQuery($household, $today))->get(),
            'spent_this_week' => round(array_sum(array_column($week, 'total')), 2),
            'overdue' => array_slice((new OverdueChoresQuery($household, $today))->get(), 0, 5),
            // Chores that slipped enough this week for LearnChoreFrequencies to change them
            'learned' => $household->chores()->with('room')
                ->where('learned_on', '>', $today->subDays(7)->toDateString())
                ->orderBy('name')->get()
                ->map(fn (Chore $c) => ['chore' => "{$c->room->name}: {$c->name}", 'from' => $c->learned_from_days, 'to' => $c->every_days])
                ->values()->all(),
        ];
    }

    /**
     * "€820 of €1,000 at this pace · 3 chores overdue · protein low"
     *
     * @param  array<string, mixed>  $shared
     * @param  array<string, mixed>  $nutrition
     */
    public static function pushText(Household $household, array $shared, array $nutrition): string
    {
        $c = $household->currency;
        $b = $shared['budget'];
        $parts = [$b['forecast'] !== null && $b['budget'] !== null
            ? Money::whole($b['forecast'], $c).' of '.Money::whole($b['budget'], $c).' at this pace'
            : Money::whole($shared['spent_this_week'], $c).' spent this week'];
        $overdue = count($shared['overdue']);
        $parts[] = match ($overdue) {
            0 => 'chores on track',
            1 => '1 chore overdue',
            default => "{$overdue} chores overdue",
        };
        $gaps = array_column($nutrition['gaps'], 'key');
        if ($gaps) {
            $parts[] = str_replace('fiber', 'fibre', implode(' and ', $gaps)).' low';
        }

        return implode(' · ', $parts);
    }
}
