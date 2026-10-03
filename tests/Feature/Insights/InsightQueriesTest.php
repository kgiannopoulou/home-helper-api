<?php

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use App\Models\Budget;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Expense;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\Room;
use App\Models\ShoppingTrip;
use App\Queries\BudgetForecastQuery;
use App\Queries\RunOutQuery;
use App\Queries\ShoppingDayQuery;
use App\Queries\SlippingChoresQuery;
use App\Queries\WeeklySpendingQuery;
use Carbon\CarbonImmutable;

/*
 * The same cases as the phone's Jest tests (__tests__/hub/predictions.test.ts and
 * __tests__/kitchen/inventory.test.ts), so SQL and TypeScript give the same answers.
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->other = Household::factory()->create();
});

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

function spend(Household $home, string $date, float $amount, ExpenseCategory $category = ExpenseCategory::Groceries, ExpenseSource $source = ExpenseSource::Manual): Expense
{
    return Expense::factory()->for($home)->create(['date' => $date, 'amount' => $amount, 'category' => $category, 'source' => $source]);
}

function bought(Household $home, string $name, array $days): InventoryItem
{
    $item = InventoryItem::factory()->for($home)->create(['name' => $name]);
    foreach ($days as $d) {
        Purchase::factory()->for($home)->for($item)->create(['bought_on' => $d]);
    }

    return $item;
}

function chore(Household $home, int $everyDays, array $doneOn, array $extra = []): Chore
{
    $chore = Chore::factory()->for($home)->for(Room::factory()->for($home)->create(['name' => 'Room '.$everyDays.count($doneOn).mt_rand()]))
        ->create(['every_days' => $everyDays, ...$extra]);
    foreach ($doneOn as $d) {
        ChoreCompletion::factory()->for($home)->for($chore)->create(['done_at' => "$d 10:00:00"]);
    }

    return $chore;
}

describe('weekly spending', function () {
    test('per week and category, against the week before', function () {
        spend($this->home, '2026-09-15', 40);                                  // week of 14 Sep
        spend($this->home, '2026-09-16', 30, ExpenseCategory::EatingOut);
        spend($this->home, '2026-09-22', 30);                                  // week of 21 Sep
        spend($this->home, '2026-09-26', 50);
        spend($this->home, '2026-09-23', 20, ExpenseCategory::Fun);
        spend($this->home, '2026-09-29', 60);                                  // week of 28 Sep
        spend($this->home, '2026-09-30', 15, ExpenseCategory::EatingOut);
        spend($this->home, '2026-09-29', 999)->delete();
        spend($this->other, '2026-09-29', 500);

        $rows = (new WeeklySpendingQuery($this->home, day('2026-09-30'), weeks: 2))->get();

        expect($rows)->toBe([
            ['week_start' => '2026-09-21', 'category' => 'groceries', 'total' => 80.0, 'expenses' => 2, 'previous_total' => 40.0, 'change_pct' => 100],
            ['week_start' => '2026-09-21', 'category' => 'fun', 'total' => 20.0, 'expenses' => 1, 'previous_total' => null, 'change_pct' => null],
            ['week_start' => '2026-09-28', 'category' => 'groceries', 'total' => 60.0, 'expenses' => 1, 'previous_total' => 80.0, 'change_pct' => -25],
            // Eating out two weeks earlier is not "the week before"
            ['week_start' => '2026-09-28', 'category' => 'eating_out', 'total' => 15.0, 'expenses' => 1, 'previous_total' => null, 'change_pct' => null],
        ]);
    });

    test('the view groups by Monday week', function () {
        spend($this->home, '2026-09-27', 10); // Sunday
        spend($this->home, '2026-09-28', 20); // Monday

        $weeks = DB::table('v_weekly_spending')->where('household_id', $this->home->id)->orderBy('week_start')->pluck('total', 'week_start');

        expect($weeks->map(fn ($t) => (float) $t)->all())->toBe(['2026-09-21' => 10.0, '2026-09-28' => 20.0]);
    });
});

describe('run out', function () {
    test('uses the average gap between purchase days', function () {
        bought($this->home, 'Toilet paper', ['2026-09-02', '2026-09-16', '2026-09-30']);
        bought($this->home, 'Milk', ['2026-09-21', '2026-09-26', '2026-10-01', '2026-10-01']); // twice on the 1st counts once

        $rows = (new RunOutQuery($this->home, day('2026-09-30')))->get();

        expect(array_map(fn ($r) => array_intersect_key($r, array_flip(['name', 'times_bought', 'every_days', 'runs_out_on', 'days_left'])), $rows))->toBe([
            ['name' => 'Milk', 'times_bought' => 3, 'every_days' => 5, 'runs_out_on' => '2026-10-06', 'days_left' => 6],
            ['name' => 'Toilet paper', 'times_bought' => 3, 'every_days' => 14, 'runs_out_on' => '2026-10-14', 'days_left' => 14],
        ]);
    });

    test('needs three purchase days, and ignores deleted ones and other households', function () {
        bought($this->home, 'Rice', ['2026-09-01', '2026-09-15']);
        $eggs = bought($this->home, 'Eggs', ['2026-09-01', '2026-09-08', '2026-09-15']);
        $eggs->purchases()->first()->delete();
        bought($this->other, 'Coffee', ['2026-09-01', '2026-09-08', '2026-09-15']);

        expect((new RunOutQuery($this->home, day('2026-09-30')))->get())->toBe([]);
    });
});

describe('slipping chores', function () {
    test('kept skipping a weekly chore: every 2 weeks', function () {
        chore($this->home, 7, ['2026-08-20', '2026-09-01', '2026-09-13', '2026-09-26'], ['name' => 'Vacuum']);

        expect((new SlippingChoresQuery($this->home, day('2026-10-03')))->get())->sequence(
            fn ($c) => $c->toMatchArray(['name' => 'Vacuum', 'every_days' => 7, 'trend' => 'late', 'typical_days' => 12, 'gaps' => [12, 12, 13], 'suggested_every_days' => 14]),
        );
    });

    test('a long overdue stretch now counts too', function () {
        chore($this->home, 7, ['2026-08-25', '2026-09-06', '2026-09-18']);

        $rows = (new SlippingChoresQuery($this->home, day('2026-10-03')))->get();

        expect($rows[0]['gaps'])->toBe([12, 12, 15])
            ->and($rows[0]['suggested_every_days'])->toBe(14);
    });

    test('always done early: more often', function () {
        chore($this->home, 14, ['2026-09-02', '2026-09-09', '2026-09-17', '2026-09-24', '2026-10-01']);

        expect((new SlippingChoresQuery($this->home, day('2026-10-03')))->get()[0])
            ->toMatchArray(['trend' => 'early', 'typical_days' => 7, 'suggested_every_days' => 7]);
    });

    test('a mixed or short record, a recent change or a fixed frequency changes nothing', function () {
        $skipped = ['2026-08-20', '2026-09-01', '2026-09-13', '2026-09-26'];
        chore($this->home, 7, ['2026-09-05', '2026-09-17', '2026-09-24', '2026-10-01']);
        chore($this->home, 7, ['2026-09-10', '2026-09-24']);
        chore($this->home, 7, $skipped, ['learned_from_days' => 3, 'learned_on' => '2026-09-25']);
        chore($this->home, 7, $skipped, ['fixed_frequency' => true]);
        chore($this->other, 7, $skipped);

        expect((new SlippingChoresQuery($this->home, day('2026-10-03')))->get())->toBe([]);
    });
});

describe('shopping day', function () {
    beforeEach(function () {
        // Saturdays in September 2026 as trips, plus a Wednesday top-up logged only as an expense
        foreach (['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26'] as $d) {
            ShoppingTrip::factory()->for($this->home)->create(['date' => $d]);
        }
        spend($this->home, '2026-09-05', 60);  // the same shop as the trip
        spend($this->home, '2026-09-16', 45);
        spend($this->home, '2026-09-13', 4);   // a snack, not a shop
        spend($this->home, '2026-09-14', 30, ExpenseCategory::Fun);
    });

    test('learns the usual weekday and the next one from today', function () {
        $result = (new ShoppingDayQuery($this->home, day('2026-09-30')))->get();

        expect($result['usual'])->toBe(['weekday' => 6, 'name' => 'Saturday', 'next_on' => '2026-10-03', 'share' => 0.8])
            ->and($result['shops'])->toBe(5)
            ->and($result['weekdays'][1])->toBe(['weekday' => 3, 'name' => 'Wednesday', 'shops' => 1, 'share' => 0.2]);

        // Today is shopping day
        expect((new ShoppingDayQuery($this->home, day('2026-10-03')))->get()['usual']['next_on'])->toBe('2026-10-03');
    });

    test('needs a real pattern', function () {
        // On the 14th only two shops have happened
        expect((new ShoppingDayQuery($this->home, day('2026-09-14')))->get())->toMatchArray(['usual' => null, 'shops' => 2]);

        foreach (['2026-09-14', '2026-09-16', '2026-09-18', '2026-09-20'] as $d) {
            ShoppingTrip::factory()->for($this->other)->create(['date' => $d]);
        }
        expect((new ShoppingDayQuery($this->other, day('2026-09-30')))->get()['usual'])->toBeNull();
    });
});

describe('budget forecast', function () {
    beforeEach(function () {
        Budget::factory()->for($this->home)->overall()->create(['amount' => 1000]);
        Budget::factory()->for($this->home)->create(['period' => BudgetPeriod::Week, 'category' => ExpenseCategory::Groceries]);
    });

    test('mid-month without history: the pace', function () {
        spend($this->home, '2026-09-05', 300);
        spend($this->home, '2026-09-14', 290);

        expect((new BudgetForecastQuery($this->home, day('2026-09-15')))->get())
            ->toMatchArray(['forecast' => 1180.0, 'budget' => 1000.0, 'over_budget' => 180.0, 'spent' => 590.0]);
    });

    test('early in the month it leans on how past months went', function () {
        foreach (['2026-07', '2026-08'] as $m) {
            spend($this->home, "$m-02", 200);
            spend($this->home, "$m-20", 700);
        }
        spend($this->home, '2026-09-02', 300);

        // History says 300 × 900/200 = 1350; the pace says 300/3 × 30 = 3000; on day 3 of 30 it's 10% pace
        expect((new BudgetForecastQuery($this->home, day('2026-09-03')))->get())->toMatchArray([
            'forecast' => 1515.0,
            'projected_from_pace' => 3000.0,
            'history' => ['months' => 2, 'usual_by_this_day' => 200.0, 'average_month' => 900.0],
        ]);
    });

    test('bills are not extrapolated', function () {
        spend($this->home, '2026-09-01', 600, ExpenseCategory::Bills, ExpenseSource::Recurring);
        spend($this->home, '2026-09-10', 150);

        expect((new BudgetForecastQuery($this->home, day('2026-09-15')))->get()['forecast'])->toBe(900.0);
    });

    test('too early with no history, or nothing spent: no forecast', function () {
        spend($this->home, '2026-09-02', 50);
        expect((new BudgetForecastQuery($this->home, day('2026-09-03')))->get()['forecast'])->toBeNull();

        expect((new BudgetForecastQuery($this->other, day('2026-09-20')))->get())
            ->toMatchArray(['forecast' => null, 'spent' => 0.0, 'budget' => null]);
    });

    test('without a budget it still says where the month will end', function () {
        spend($this->other, '2026-09-05', 600);

        expect((new BudgetForecastQuery($this->other, day('2026-09-15')))->get())
            ->toMatchArray(['forecast' => 1200.0, 'budget' => null, 'over_budget' => null]);
    });
});
