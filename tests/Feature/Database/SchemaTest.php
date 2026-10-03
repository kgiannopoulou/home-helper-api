<?php

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Enums\HouseholdRole;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Expense;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\SleepEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

test('deleting a household deletes all of its data', function () {
    $home = Household::factory()->create();
    $other = Household::factory()->create();
    Expense::factory()->count(3)->for($home)->create();
    Purchase::factory()->for(InventoryItem::factory()->for($home))->for($home)->create();
    ChoreCompletion::factory()->create(['chore_id' => Chore::factory()->for($home)->create()->id]);
    Expense::factory()->for($other)->create();

    $home->delete();

    expect(Expense::withTrashed()->count())->toBe(1)
        ->and(InventoryItem::withTrashed()->count())->toBe(0)
        ->and(Purchase::withTrashed()->count())->toBe(0)
        ->and(Chore::withTrashed()->count())->toBe(0)
        ->and(ChoreCompletion::withTrashed()->count())->toBe(0);
});

test('deleting a user keeps the household money but drops their personal logs', function () {
    $home = Household::factory()->create();
    $user = User::factory()->create();
    $expense = Expense::factory()->for($home)->create(['user_id' => $user->id]);
    SleepEntry::factory()->for($home)->for($user)->create();

    $user->delete();

    expect($expense->fresh()->user_id)->toBeNull()
        ->and(SleepEntry::withTrashed()->count())->toBe(0);
});

test('CHECK constraints reject impossible values', function (Closure $insert) {
    expect($insert)->toThrow(QueryException::class, 'Check constraint');
})->with([
    'negative expense' => fn () => Expense::factory()->create(['amount' => -5]),
    'bill on the 31st' => fn () => Household::factory()->create()->recurringBills()->create([
        'name' => 'Rent', 'amount' => 600, 'category' => ExpenseCategory::Bills, 'day_of_month' => 31,
    ]),
    'sleep quality 6' => fn () => SleepEntry::factory()->create(['quality' => 6]),
    'waking before bed' => fn () => SleepEntry::factory()->create([
        'bed_at' => '2026-10-03 07:00:00', 'woke_at' => '2026-10-03 06:00:00',
    ]),
]);

test('an inventory name is unique per household', function () {
    $home = Household::factory()->create();
    InventoryItem::factory()->for($home)->create(['name' => 'Milk']);

    expect(fn () => InventoryItem::factory()->for($home)->create(['name' => 'Milk']))
        ->toThrow(UniqueConstraintViolationException::class);

    // Another household can have its own milk
    InventoryItem::factory()->create(['name' => 'Milk']);
    expect(InventoryItem::where('name', 'Milk')->count())->toBe(2);
});

test('a soft-deleted item does not block adding it again', function () {
    $home = Household::factory()->create();
    InventoryItem::factory()->for($home)->create(['name' => 'Milk'])->delete();

    InventoryItem::factory()->for($home)->create(['name' => 'Milk']);

    expect(InventoryItem::withTrashed()->where('name', 'Milk')->count())->toBe(2)
        ->and(InventoryItem::where('name', 'Milk')->count())->toBe(1);
});

test('a household has one overall budget per period', function () {
    $home = Household::factory()->create();
    $home->budgets()->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 1500]);
    $home->budgets()->create(['period' => BudgetPeriod::Month, 'category' => ExpenseCategory::Fun, 'amount' => 80]);

    expect(fn () => $home->budgets()->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 900]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('MySQL works out hours slept', function () {
    $entry = SleepEntry::factory()->create([
        'bed_at' => '2026-10-02 23:15:00',
        'woke_at' => '2026-10-03 07:00:00',
    ]);

    expect($entry->fresh()->hours)->toBe('7.75');
});

test('latest purchase and last completion come from the child tables', function () {
    $home = Household::factory()->create();
    $milk = InventoryItem::factory()->for($home)->create();
    foreach (['2026-09-05', '2026-09-26', '2026-09-12'] as $day) {
        Purchase::factory()->for($home)->for($milk)->create(['bought_on' => $day]);
    }
    $oven = Chore::factory()->for($home)->create();
    foreach (['2026-08-01 10:00', '2026-09-20 18:30'] as $at) {
        ChoreCompletion::factory()->for($home)->for($oven)->create(['done_at' => $at]);
    }

    expect($milk->latestPurchase->bought_on->toDateString())->toBe('2026-09-26')
        ->and($milk->purchases->pluck('bought_on')->map->toDateString()->all())->toBe(['2026-09-05', '2026-09-12', '2026-09-26'])
        ->and($oven->lastCompletion->done_at->toDateTimeString())->toBe('2026-09-20 18:30:00');
});

test('the demo seeder builds one household with six months of data', function () {
    Carbon::setTestNow('2026-10-03 12:00');
    $this->seed();

    $home = Household::sole();
    $owner = $home->users()->wherePivot('role', HouseholdRole::Owner)->sole();

    expect($owner->email)->toBe('demo@homehelper.test')
        ->and($home->users)->toHaveCount(2)
        ->and($home->shoppingTrips()->min('date'))->toBeGreaterThanOrEqual('2026-04-01')
        // Every weekly shop is on a Saturday (DAYOFWEEK: 7 = Saturday)
        ->and($home->shoppingTrips()->whereRaw('dayofweek(date) <> 7')->count())->toBe(0)
        ->and($home->purchases()->count())->toBeGreaterThan(100);

    // At least one month is over the €1,650 monthly budget
    $overBudget = DB::table('expenses')
        ->selectRaw("date_format(date, '%Y-%m') as month, sum(amount) as total")
        ->groupBy('month')
        ->havingRaw('total > 1650')
        ->count();
    expect($overBudget)->toBeGreaterThanOrEqual(1);

    // The oven is set to every 14 days but is never done that often
    $oven = $home->chores()->where('name', 'Clean the oven')->sole();
    $times = $oven->completions()->orderBy('done_at')->pluck('done_at');
    $gaps = $times->sliding(2)->map(fn ($pair) => $pair->first()->diffInDays($pair->last()));
    expect($gaps->min())->toBeGreaterThan(14);
});
