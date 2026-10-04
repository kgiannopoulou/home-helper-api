<?php

use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use App\Enums\HouseholdRole;
use App\Enums\JobStatus;
use App\Enums\ShoppingSource;
use App\Enums\StockLevel;
use App\Enums\SupplyLevel;
use App\Jobs\ApplyRecurringBills;
use App\Jobs\BudgetAlert;
use App\Jobs\HouseholdJob;
use App\Jobs\LearnChoreFrequencies;
use App\Jobs\PrepareShoppingList;
use App\Jobs\WeeklySummary;
use App\Mail\WeeklySummaryMail;
use App\Models\Budget;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Device;
use App\Models\Expense;
use App\Models\FoodEntry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\JobRun;
use App\Models\Purchase;
use App\Models\RecurringBill;
use App\Models\Room;
use App\Models\ShoppingItem;
use App\Models\ShoppingTrip;
use App\Models\Supply;
use App\Models\User;
use App\Models\WaterEntry;
use App\Models\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/** A household job that always fails, to test the job_runs log. */
class ExplodingJob extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        throw new RuntimeException('Boom');
    }
}

const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

beforeEach(function () {
    $this->home = Household::factory()->create(['name' => 'Patission 12']);
    $this->me = User::factory()->create(['name' => 'Konstantina']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->phone = Device::forceCreate(['user_id' => $this->me->id, 'expo_token' => 'ExponentPushToken[me-phone]', 'platform' => 'android']);

    // Expo accepts every message, unless a test sets another reply
    $this->expoReply = fn (Request $r) => Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'ticket'], $r->data())]);
    Http::fake([EXPO_URL => fn (Request $r) => ($this->expoReply)($r)]);
});

/** Runs a job for one household on a given day at home, as a queue worker would. */
function runFor(string $job, Household $household, string $date): JobRun
{
    /** @var class-string<HouseholdJob> $job */
    (new $job($household, $date))->handle();

    return $household->jobRuns()->where('job', $job::name())->latest('id')->firstOrFail();
}

/** @return list<array<string, mixed>> every message sent to Expo */
function pushes(): array
{
    return Http::recorded()->filter(fn ($pair) => $pair[0]->url() === EXPO_URL)->flatMap(fn ($pair) => $pair[0]->data())->values()->all();
}

describe('scheduling and fan-out', function () {
    test('each job is scheduled at home time', function () {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [$e->description => [$e->expression, $e->timezone]]);

        expect($events->all())->toMatchArray([
            ApplyRecurringBills::class => ['0 6 * * *', 'Europe/Athens'],
            LearnChoreFrequencies::class => ['0 4 * * *', 'Europe/Athens'],
            PrepareShoppingList::class => ['0 17 * * *', 'Europe/Athens'],
            BudgetAlert::class => ['0 18 * * *', 'Europe/Athens'],
            WeeklySummary::class => ['0 19 * * 0', 'Europe/Athens'],
        ]);
    });

    test('the scheduled run queues one job per household, all for the same day', function () {
        Queue::fake();
        Household::factory()->count(2)->create();

        (new BudgetAlert(null, '2026-10-04'))->handle();

        Queue::assertPushed(BudgetAlert::class, 3);
        Queue::assertPushed(BudgetAlert::class, fn (BudgetAlert $job) => $job->household?->is($this->home) && $job->date === '2026-10-04');
        expect(JobRun::count())->toBe(0);
    });

    test('household:run queues a job by name', function () {
        Queue::fake();

        $this->artisan('household:run', ['job' => 'PrepareShoppingList', '--date' => '2026-10-09'])->assertSuccessful();
        $this->artisan('household:run', ['job' => 'Household'])->assertFailed();

        Queue::assertPushed(PrepareShoppingList::class, fn (PrepareShoppingList $job) => $job->household === null && $job->date === '2026-10-09');
    });

    test('every run is logged with its status and duration', function () {
        $run = runFor(ApplyRecurringBills::class, $this->home, '2026-10-04');

        expect($run->status)->toBe(JobStatus::Succeeded)
            ->and($run->for_date->toDateString())->toBe('2026-10-04')
            ->and($run->attempt)->toBe(1)
            ->and($run->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
            ->and($run->finished_at)->not->toBeNull()
            ->and($run->summary)->toBe(['added' => []]);
    });

    test('a failed run is logged and thrown again, so the queue retries it', function () {
        expect(fn () => (new ExplodingJob($this->home, '2026-10-04'))->handle())->toThrow(RuntimeException::class, 'Boom');

        $run = JobRun::sole();
        expect($run->status)->toBe(JobStatus::Failed)
            ->and($run->error)->toBe('RuntimeException: Boom')
            ->and($run->job)->toBe('ExplodingJob');
    });
});

describe('PrepareShoppingList', function () {
    beforeEach(function () {
        // Shops on three Saturdays, so Saturday is the usual day
        foreach (['2026-09-12', '2026-09-19', '2026-09-26'] as $d) {
            ShoppingTrip::factory()->for($this->home)->create(['date' => $d]);
        }
        InventoryItem::factory()->for($this->home)->create(['name' => 'Milk', 'level' => StockLevel::Low]);
        InventoryItem::factory()->for($this->home)->create(['name' => 'Olive oil', 'level' => StockLevel::Full]);
        InventoryItem::factory()->for($this->home)->create(['name' => 'Bread', 'level' => StockLevel::Empty]);
        $eggs = InventoryItem::factory()->for($this->home)->create(['name' => 'Eggs', 'level' => StockLevel::Full]);
        foreach (['2026-09-12', '2026-09-19', '2026-09-26'] as $d) {
            Purchase::factory()->for($this->home)->for($eggs)->create(['bought_on' => $d]); // runs out 3 Oct
        }
        Supply::factory()->for($this->home)->create(['name' => 'Dish soap', 'level' => SupplyLevel::Out]);
        Supply::factory()->for($this->home)->create(['name' => 'Bleach', 'level' => SupplyLevel::Half]);
        // Already on the list, written differently
        ShoppingItem::factory()->for($this->home)->create(['name' => 'bread ', 'checked' => false]);
    });

    test('the evening before shopping day, it fills the list and tells the household', function () {
        $run = runFor(PrepareShoppingList::class, $this->home, '2026-10-02');

        $added = $this->home->shoppingItems()->where('source', ShoppingSource::Inventory)->orderBy('name')->pluck('name')->all();
        expect($added)->toBe(['Dish soap', 'Eggs', 'Milk'])
            ->and($run->summary['shopping_day'])->toBe('2026-10-03')
            ->and(array_column($run->summary['added'], 'why', 'name'))->toBe(['Eggs' => 'runs out around 2026-10-03', 'Milk' => 'low', 'Dish soap' => 'empty']);

        expect(pushes())->toHaveCount(1)
            ->and(pushes()[0])->toMatchArray([
                'to' => 'ExponentPushToken[me-phone]',
                'title' => '🛒 Tomorrow is Saturday shopping',
                'body' => 'Added Eggs, Milk and Dish soap to the list.',
            ])
            ->and((array) pushes()[0]['data'])->toBe(['url' => '/shopping']);
    });

    test('running it again adds nothing and sends nothing', function () {
        runFor(PrepareShoppingList::class, $this->home, '2026-10-02');
        $run = runFor(PrepareShoppingList::class, $this->home, '2026-10-02');

        expect($run->summary['added'])->toBe([])
            ->and($this->home->shoppingItems()->count())->toBe(4)
            ->and(pushes())->toHaveCount(1);
    });

    test('it waits for the day before', function () {
        $run = runFor(PrepareShoppingList::class, $this->home, '2026-10-01');

        expect($run->summary)->toEqual(['skipped' => 'not the day before', 'shopping_day' => '2026-10-03'])
            ->and($this->home->shoppingItems()->count())->toBe(1);
        Http::assertNothingSent();
    });

    test('a household without a usual shopping day is left alone', function () {
        $other = Household::factory()->create();

        expect(runFor(PrepareShoppingList::class, $other, '2026-10-02')->summary)->toBe(['skipped' => 'no usual shopping day yet']);
    });

    test('long lists are shortened in the push', function () {
        expect(PrepareShoppingList::listText(['Milk']))->toBe('Added Milk to the list.')
            ->and(PrepareShoppingList::listText(['A', 'B', 'C', 'D']))->toBe('Added A, B, C and D to the list.')
            ->and(PrepareShoppingList::listText(['A', 'B', 'C', 'D', 'E', 'F']))->toBe('Added A, B, C and 3 more to the list.');
    });
});

describe('ApplyRecurringBills', function () {
    beforeEach(function () {
        $bill = fn (string $name, int $day, array $extra = []) => RecurringBill::factory()->for($this->home)
            ->create(['name' => $name, 'day_of_month' => $day, 'amount' => 50, ...$extra]);
        $this->rent = $bill('Rent', 1, ['amount' => 650]);
        $this->phoneBill = $bill('Phone', 5);
        $bill('Netflix', 20);
        $bill('Gym', 1, ['active' => false]);
        $water = $bill('Water', 3);
        // This month's water bill was added and then deleted on purpose
        Expense::factory()->for($this->home)->create(['recurring_bill_id' => $water->id, 'date' => '2026-10-03', 'amount' => 50, 'source' => ExpenseSource::Recurring])->delete();
    });

    test('adds each active bill whose day has come this month, once', function () {
        $run = runFor(ApplyRecurringBills::class, $this->home, '2026-10-05');

        $expenses = $this->home->expenses()->orderBy('date')->get();
        expect($expenses->map(fn (Expense $e) => [$e->note, $e->date->toDateString(), (float) $e->amount, $e->source, $e->recurring_bill_id])->all())->toBe([
            ['Rent', '2026-10-01', 650.0, ExpenseSource::Recurring, $this->rent->id],
            ['Phone', '2026-10-05', 50.0, ExpenseSource::Recurring, $this->phoneBill->id],
        ])->and($run->summary['added'])->toHaveCount(2);

        runFor(ApplyRecurringBills::class, $this->home, '2026-10-06');
        expect($this->home->expenses()->count())->toBe(2);
    });

    test('a missed day is caught up later in the month, but past months are not backfilled', function () {
        runFor(ApplyRecurringBills::class, $this->home, '2026-10-25');

        expect($this->home->expenses()->pluck('note')->sort()->values()->all())->toBe(['Netflix', 'Phone', 'Rent'])
            ->and($this->home->expenses()->where('date', '<', '2026-10-01')->count())->toBe(0);
    });
});

describe('BudgetAlert', function () {
    beforeEach(function () {
        Budget::factory()->for($this->home)->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 1000]);
    });

    test('tells the household when the month is heading over budget, then only when it gets worse', function () {
        Expense::factory()->for($this->home)->create(['date' => '2026-10-05', 'amount' => 900, 'category' => ExpenseCategory::Groceries]);

        // 900 by the 20th → 1,395 at this pace
        $first = runFor(BudgetAlert::class, $this->home, '2026-10-20');
        expect($first->summary)->toMatchArray(['forecast' => 1395.0, 'over_budget' => 395.0, 'alerted' => true])
            ->and(pushes()[0]['body'])->toBe('At this pace: €1,395 of €1,000 this month, €395 over. Spent €900 so far.');

        // Next day: still over, but not by 10% of the budget more
        $quiet = runFor(BudgetAlert::class, $this->home, '2026-10-21');
        expect($quiet->summary)->toMatchArray(['alerted' => false, 'quiet' => 'alerted already this month'])
            ->and(pushes())->toHaveCount(1);

        Expense::factory()->for($this->home)->create(['date' => '2026-10-21', 'amount' => 300, 'category' => ExpenseCategory::Fun]);
        expect(runFor(BudgetAlert::class, $this->home, '2026-10-21')->summary['alerted'])->toBeTrue()
            ->and(pushes())->toHaveCount(2);
    });

    test('says nothing while under budget', function () {
        Expense::factory()->for($this->home)->create(['date' => '2026-10-05', 'amount' => 300, 'category' => ExpenseCategory::Groceries]);

        expect(runFor(BudgetAlert::class, $this->home, '2026-10-20')->summary)->toMatchArray(['over_budget' => -535.0, 'alerted' => false]);
        Http::assertNothingSent();
    });

    test('an alert nobody received does not count, so it comes again once a phone is registered', function () {
        $this->phone->delete();
        Expense::factory()->for($this->home)->create(['date' => '2026-10-05', 'amount' => 900, 'category' => ExpenseCategory::Groceries]);

        expect(runFor(BudgetAlert::class, $this->home, '2026-10-20')->summary['alerted'])->toBeFalse();

        Device::forceCreate(['user_id' => $this->me->id, 'expo_token' => 'ExponentPushToken[new-phone]']);
        expect(runFor(BudgetAlert::class, $this->home, '2026-10-21')->summary['alerted'])->toBeTrue();
    });
});

describe('LearnChoreFrequencies', function () {
    test('moves a chore you keep doing late one step, and remembers the old frequency', function () {
        $room = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);
        $oven = Chore::factory()->for($this->home)->for($room)->create(['name' => 'Clean the oven', 'every_days' => 14]);
        $fixed = Chore::factory()->for($this->home)->for($room)->create(['name' => 'Mop', 'every_days' => 14, 'fixed_frequency' => true]);
        foreach ([$oven, $fixed] as $chore) {
            foreach (['2026-07-01', '2026-07-31', '2026-08-28', '2026-10-02'] as $d) {
                ChoreCompletion::factory()->for($this->home)->for($chore)->create(['done_at' => "$d 10:00:00"]);
            }
        }

        $run = runFor(LearnChoreFrequencies::class, $this->home, '2026-10-04');

        expect($oven->fresh()->only('every_days', 'learned_from_days'))->toBe(['every_days' => 30, 'learned_from_days' => 14])
            ->and($oven->fresh()->learned_on->toDateString())->toBe('2026-10-04')
            ->and($fixed->fresh()->every_days)->toBe(14)
            ->and($run->summary['changed'])->toEqual([['chore' => 'Kitchen: Clean the oven', 'from' => 14, 'to' => 30, 'trend' => 'late']]);

        // Not again the next day: 3 cycles have to pass after a change
        expect(runFor(LearnChoreFrequencies::class, $this->home, '2026-10-05')->summary['changed'])->toBe([]);
    });
});

describe('WeeklySummary', function () {
    beforeEach(function () {
        Mail::fake();
        $this->partner = User::factory()->create(['name' => 'Nikos']);
        $this->home->addMember($this->partner);
        Device::forceCreate(['user_id' => $this->partner->id, 'expo_token' => 'ExponentPushToken[partner-phone]']);

        Budget::factory()->for($this->home)->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 1000]);
        Expense::factory()->for($this->home)->create(['date' => '2026-10-02', 'amount' => 120, 'category' => ExpenseCategory::Groceries]);
        Expense::factory()->for($this->home)->create(['date' => '2026-09-28', 'amount' => 30, 'category' => ExpenseCategory::Fun]);
        $room = Room::factory()->for($this->home)->create(['name' => 'Bathroom']);
        $chore = Chore::factory()->for($this->home)->for($room)->create(['name' => 'Scrub the tub', 'every_days' => 7]);
        ChoreCompletion::factory()->for($this->home)->for($chore)->create(['done_at' => '2026-09-20 10:00:00']);

        // Me: 70 kg, so 84 g protein; I ate 50 g a day. The partner ate enough.
        Weight::factory()->for($this->home)->for($this->me)->create(['date' => '2026-10-01', 'kg' => 70]);
        Weight::factory()->for($this->home)->for($this->partner)->create(['date' => '2026-10-01', 'kg' => 70]);
        foreach (['2026-09-30', '2026-10-01', '2026-10-02'] as $d) {
            FoodEntry::factory()->for($this->home)->for($this->me)->create(['date' => $d, 'kcal' => 2000, 'protein' => 50, 'fiber' => 30]);
            FoodEntry::factory()->for($this->home)->for($this->partner)->create(['date' => $d, 'kcal' => 2000, 'protein' => 100, 'fiber' => 30]);
            WaterEntry::factory()->for($this->home)->for($this->partner)->create(['date' => $d, 'ml' => 2500]);
        }
    });

    test('emails and pushes each member their week, with only their own food', function () {
        $run = runFor(WeeklySummary::class, $this->home, '2026-10-04');

        Mail::assertSentCount(2);
        Mail::assertSent(WeeklySummaryMail::class, fn (WeeklySummaryMail $m) => $m->hasTo($this->me->email)
            && array_column($m->nutrition['gaps'], 'key') === ['protein']
            && $m->nutrition['gaps'][0] === ['key' => 'protein', 'average' => 50.0, 'target' => 84, 'ratio' => 0.6]);
        Mail::assertSent(WeeklySummaryMail::class, fn (WeeklySummaryMail $m) => $m->hasTo($this->partner->email) && $m->nutrition['gaps'] === []);

        $mail = Mail::sent(WeeklySummaryMail::class, fn ($m) => $m->hasTo($this->me->email))->first();
        expect($mail->shared['spent_this_week'])->toBe(150.0)
            ->and($mail->shared['overdue'][0])->toMatchArray(['name' => 'Scrub the tub', 'room' => 'Bathroom', 'days_overdue' => 7])
            ->and($mail->render())->toContain('Your week, Konstantina', '€150', 'Bathroom: Scrub the tub', 'Protein', '60% of 84 g')
            ->not->toContain('Nikos');

        $byPhone = collect(pushes())->keyBy('to');
        expect($byPhone['ExponentPushToken[me-phone]']['body'])->toEndWith('· 1 chore overdue · protein low')
            ->and($byPhone['ExponentPushToken[partner-phone]']['body'])->toEndWith('· 1 chore overdue')
            ->and($run->summary['members'])->toHaveCount(2);
    });
});

describe('Expo push', function () {
    test('a phone that uninstalled the app is forgotten', function () {
        $this->expoReply = fn () => Http::response(['data' => [['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]]]);
        Expense::factory()->for($this->home)->create(['date' => '2026-10-05', 'amount' => 900]);
        Budget::factory()->for($this->home)->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 100]);

        $run = runFor(BudgetAlert::class, $this->home, '2026-10-20');

        expect($run->summary['push'])->toEqual(['devices' => 1, 'sent' => 0, 'failed' => 1])
            ->and(Device::count())->toBe(0);
    });

    test('Expo being down does not fail the job, whose work is already done', function () {
        $this->expoReply = fn () => Http::response('Bad gateway', 502);
        InventoryItem::factory()->for($this->home)->create(['name' => 'Milk', 'level' => StockLevel::Low]);
        foreach (['2026-09-12', '2026-09-19', '2026-09-26'] as $d) {
            ShoppingTrip::factory()->for($this->home)->create(['date' => $d]);
        }

        $run = runFor(PrepareShoppingList::class, $this->home, '2026-10-02');

        expect($run->status)->toBe(JobStatus::Succeeded)
            ->and($run->summary['push'])->toEqual(['devices' => 1, 'sent' => 0, 'failed' => 1])
            ->and(Device::count())->toBe(1)
            ->and($this->home->shoppingItems()->pluck('name')->all())->toBe(['Milk']);
    });

    test('sends the access token when one is set', function () {
        config(['services.expo.access_token' => 'secret']);
        Expense::factory()->for($this->home)->create(['date' => '2026-10-05', 'amount' => 900]);
        Budget::factory()->for($this->home)->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 100]);

        runFor(BudgetAlert::class, $this->home, '2026-10-20');

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret'));
    });
});
