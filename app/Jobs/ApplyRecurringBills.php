<?php

namespace App\Jobs;

use App\Enums\ExpenseSource;
use App\Models\Household;
use App\Models\RecurringBill;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Adds each active bill as an expense on its day of the month. Same rules as
 * applyRecurring() on the phone: a bill whose day has come this month and that
 * wasn't added yet, so a missed day is caught up later in the month. Past months
 * are never backfilled.
 *
 * "Added yet" includes a deleted expense: if you deleted this month's rent, it
 * stays deleted. A phone that adds the same bill offline joins this expense when
 * it syncs (natural key recurring_bill_id + date), so it's never counted twice.
 */
class ApplyRecurringBills extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $monthStart = $today->startOfMonth();

        $due = $household->recurringBills()
            ->where('active', true)
            ->where('day_of_month', '<=', $today->day)
            ->whereDoesntHave('expenses', fn (Builder $q) => $q
                ->withTrashed()
                ->whereBetween('date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()]))
            ->orderBy('day_of_month')
            ->get();

        $added = DB::transaction(fn () => $due->map(fn (RecurringBill $bill) => $household->expenses()->create([
            'recurring_bill_id' => $bill->id,
            'date' => $monthStart->setDay($bill->day_of_month)->toDateString(),
            'amount' => $bill->amount,
            'category' => $bill->category,
            'note' => $bill->name,
            'source' => ExpenseSource::Recurring,
        ]))->all());

        return [
            'added' => array_map(fn ($e) => ['bill' => $e->note, 'date' => $e->date->toDateString(), 'amount' => (float) $e->amount], $added),
        ];
    }
}
