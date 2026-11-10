<?php

use App\Ai\Assistant;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Enums\HouseholdRole;
use App\Enums\PlantLocation;
use App\Models\AdminItem;
use App\Models\AiPendingAction;
use App\Models\Budget;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\RecurringBill;
use App\Models\Room;
use App\Models\ShoppingItem;
use App\Models\Supply;
use App\Models\Todo;
use App\Models\User;
use App\Support\HomeTime;
use App\Weather\WeatherForecast;
use App\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Tests\Support\FakeWeatherProvider;

/*
 * Each tool's answer, against rows made for the purpose (days 23–26). The
 * numbers must be the database's, not approximately.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Athens'));
    $this->home = Household::factory()->create(['currency' => 'EUR']);
    $this->user = User::factory()->create(['name' => 'Maria']);
    $this->home->addMember($this->user, HouseholdRole::Owner);
    $this->ctx = new ToolContext($this->home, $this->user, HomeTime::today(), app(Assistant::class)->start($this->home, $this->user));
});

function run(string $tool, array $input = []): array
{
    $outcome = app(ToolRegistry::class)->call($tool, $input, test()->ctx);
    expect($outcome->isError)->toBeFalse(json_encode($outcome->content));

    return $outcome->content;
}

test('household overview: members with roles, and counts', function () {
    $alex = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($alex);
    ShoppingItem::factory()->for($this->home)->count(2)->create();

    $c = run('get_household_overview');

    expect($c['members'])->toBe([
        ['name' => 'Alex', 'role' => 'member', 'is_you' => false],
        ['name' => 'Maria', 'role' => 'owner', 'is_you' => true],
    ])->and($c['counts']['shopping_items_to_buy'])->toBe(2);
});

test('shopping list: only what is still to buy, a total, and what will run out but isn\'t on it', function () {
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Milk', 'category' => 'drinks', 'price' => 1.20, 'added_by' => $this->user->id]);
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Bread', 'category' => 'food', 'price' => 2.30]);
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Soap', 'checked' => true]);
    ShoppingItem::factory()->for(Household::factory())->create(['name' => 'Someone else\'s']);

    // Coffee bought every 7 days, last 6 days ago: runs out tomorrow, and isn't on the list
    $coffee = InventoryItem::factory()->for($this->home)->create(['name' => 'Coffee']);
    foreach ([20, 13, 6] as $daysAgo) {
        $this->home->purchases()->create(['inventory_item_id' => $coffee->id, 'bought_on' => now()->subDays($daysAgo)->toDateString()]);
    }

    $c = run('get_shopping_list');

    // grouped by category, in the enum's order (food, drinks, …)
    expect(array_column($c['items'], 'name'))->toBe(['Bread', 'Milk'])
        ->and($c['items'][1]['added_by'])->toBe('Maria')
        ->and($c['estimated_total'])->toBe(3.5)
        ->and($c['currency'])->toBe('EUR')
        ->and(array_column($c['suggested_not_on_list'], 'name'))->toBe(['Coffee']);
});

test('spending summary: totals by category equal the expenses, with budgets and the previous month', function () {
    Budget::factory()->for($this->home)->overall()->create(['amount' => 1000]);
    Budget::factory()->for($this->home)->create(['category' => ExpenseCategory::Groceries, 'amount' => 300, 'period' => BudgetPeriod::Month]);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-02', 'amount' => 54.30, 'category' => 'groceries']);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-09', 'amount' => 45.70, 'category' => 'groceries']);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-01', 'amount' => 650, 'category' => 'bills']);
    Expense::factory()->for($this->home)->create(['date' => '2026-10-15', 'amount' => 80, 'category' => 'fun']);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-11', 'amount' => 999, 'category' => 'fun']); // tomorrow: not yet
    Expense::factory()->for(Household::factory())->create(['date' => '2026-11-05', 'amount' => 500]);

    $c = run('get_spending_summary');

    expect($c['month'])->toBe('2026-11')
        ->and($c['total'])->toBe(750.0)
        ->and($c['budget'])->toBe(1000.0)
        ->and($c['categories'])->toBe([
            ['category' => 'bills', 'total' => 650.0, 'expenses' => 1, 'from_recurring_bills' => 0, 'budget' => null],
            ['category' => 'groceries', 'total' => 100.0, 'expenses' => 2, 'from_recurring_bills' => 0, 'budget' => 300.0],
        ])
        ->and($c['previous_month_total'])->toBe(80.0)
        ->and($c['forecast'])->toHaveKeys(['forecast', 'budget', 'spent']);

    expect(run('get_spending_summary', ['month' => '2026-10'])['total'])->toBe(80.0);
    expect(app(ToolRegistry::class)->call('get_spending_summary', ['month' => '2027-01'], $this->ctx)->isError)->toBeTrue();
});

test('expenses: filter by category and text, largest first', function () {
    Expense::factory()->for($this->home)->create(['date' => '2026-11-01', 'amount' => 650, 'category' => 'bills', 'note' => 'Rent']);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-03', 'amount' => 42, 'category' => 'bills', 'note' => 'Power']);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-04', 'amount' => 12, 'category' => 'fun', 'note' => 'Cinema']);

    $c = run('list_expenses', ['category' => 'bills', 'order' => 'largest']);
    expect(array_column($c['expenses'], 'note'))->toBe(['Rent', 'Power'])->and($c['sum'])->toBe(692.0);

    expect(run('list_expenses', ['search' => 'rent'])['count'])->toBe(1);
});

test('upcoming bills: next date across the month end, and whether this month\'s is recorded', function () {
    $rent = RecurringBill::factory()->for($this->home)->create(['name' => 'Rent', 'amount' => 650, 'day_of_month' => 1]);
    RecurringBill::factory()->for($this->home)->create(['name' => 'Internet', 'amount' => 30, 'day_of_month' => 20]);
    RecurringBill::factory()->for($this->home)->create(['name' => 'Gym', 'amount' => 40, 'day_of_month' => 15, 'active' => false]);
    AdminItem::factory()->for($this->home)->create(['title' => 'Car insurance', 'kind' => 'renewal', 'due_on' => '2026-11-25', 'amount' => 320]);
    AdminItem::factory()->for($this->home)->create(['title' => 'Dentist', 'kind' => 'appointment', 'due_on' => '2026-11-12']);

    $c = run('get_upcoming_bills');

    expect($c['recurring'])->toBe([
        ['name' => 'Internet', 'amount' => 30.0, 'category' => 'bills', 'due_on' => '2026-11-20', 'already_recorded_this_month' => false],
        ['name' => 'Rent', 'amount' => 650.0, 'category' => 'bills', 'due_on' => '2026-12-01', 'already_recorded_this_month' => false],
    ])
        ->and(array_column($c['one_off_and_renewals'], 'title'))->toBe(['Car insurance'])
        ->and($c['total'])->toBe(1000.0);
});

test('overdue chores, and to-dos with overdue first', function () {
    $room = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);
    $oven = Chore::factory()->for($room)->create(['name' => 'Clean the oven', 'every_days' => 14]);
    ChoreCompletion::factory()->create(['chore_id' => $oven->id, 'done_at' => now()->subDays(20)]);
    Todo::factory()->for($this->home)->create(['title' => 'Call the plumber', 'due_on' => '2026-11-05']);
    Todo::factory()->for($this->home)->create(['title' => 'Renew passport', 'due_on' => null]);
    Todo::factory()->for($this->home)->create(['title' => 'Done already', 'due_on' => '2026-11-01', 'done_at' => now()]);

    $chores = run('list_overdue_chores');
    expect($chores['overdue'][0])->toMatchArray(['name' => 'Clean the oven', 'room' => 'Kitchen', 'days_overdue' => 6]);

    $todos = run('list_todos');
    expect(array_column($todos['todos'], 'title'))->toBe(['Call the plumber', 'Renew passport'])
        ->and($todos['todos'][0]['overdue'])->toBeTrue();
});

test('calendar: events in the window, at home time', function () {
    Event::factory()->for($this->home)->create(['title' => 'Dentist', 'starts_at' => CarbonImmutable::parse('2026-11-12 09:30', 'Europe/Athens')->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-12 10:30', 'Europe/Athens')->utc()]);
    Event::factory()->for($this->home)->create(['title' => 'Too far', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour()]);

    $c = run('list_upcoming_events');

    expect($c['events'])->toHaveCount(1)->and($c['events'][0])->toMatchArray(['title' => 'Dentist', 'starts' => '2026-11-12 09:30']);
});

test('kitchen: low or empty, and expiring soon', function () {
    InventoryItem::factory()->for($this->home)->create(['name' => 'Milk', 'level' => 'low', 'expires_on' => '2026-11-12']);
    InventoryItem::factory()->for($this->home)->create(['name' => 'Rice', 'level' => 'full', 'expires_on' => null]);

    $c = run('get_kitchen_inventory');

    expect(array_column($c['low_or_empty'], 'name'))->toBe(['Milk'])
        ->and(array_column($c['expiring'], 'name'))->toBe(['Milk']);
});

test('plants: what to water today, with ids for logging care', function () {
    $plant = Plant::factory()->for($this->home)->create(['name' => 'Monstera', 'water_amount_ml' => 350, 'watering_interval_days' => 7]);
    $this->home->plantCareLogs()->create(['plant_id' => $plant->id, 'type' => 'watered', 'performed_at' => now()->subDays(8)]);

    $c = run('get_plant_care_today');

    expect($c['watering'][0])->toMatchArray(['name' => 'Monstera', 'amount_ml' => 350, 'overdue_days' => 1])
        ->and($c['plants'][0]['plant_id'])->toBe($plant->id);
});

test('weather: needs the household location, then reads the forecast', function () {
    expect(run('get_weather_forecast'))->toMatchArray(['available' => false]);

    app()->instance(WeatherProvider::class, new FakeWeatherProvider(new WeatherForecast(1, 9)));
    app()->forgetInstance(ToolRegistry::class); // its tools were built with the real provider
    $this->home->update(['latitude' => 37.99, 'longitude' => 23.73]);

    $c = run('get_weather_forecast');
    expect($c['available'])->toBeTrue()->and($c['next_24_hours']['minimum_temperature_c'])->toBe(1.0);
});

test('home maintenance: renewals due and supplies running out', function () {
    AdminItem::factory()->for($this->home)->create(['title' => 'Boiler service', 'kind' => 'appointment', 'due_on' => '2026-12-01']);
    Supply::factory()->for($this->home)->create(['name' => 'Bleach', 'level' => 'out']);
    Supply::factory()->for($this->home)->create(['name' => 'Sponges', 'level' => 'full']);

    $c = run('list_home_maintenance');

    expect(array_column($c['admin'], 'title'))->toBe(['Boiler service'])
        ->and(array_column($c['supplies_low_or_out'], 'name'))->toBe(['Bleach']);
});

test('today plan: one answer from chores, calendar, to-dos, plants, bills and shopping', function () {
    $room = Room::factory()->for($this->home)->create(['name' => 'Bathroom']);
    $towels = Chore::factory()->for($room)->create(['name' => 'Wash towels', 'every_days' => 7]);
    ChoreCompletion::factory()->create(['chore_id' => $towels->id, 'done_at' => now()->subDays(7)]);
    Event::factory()->for($this->home)->create(['title' => 'Parents visit', 'starts_at' => CarbonImmutable::parse('2026-11-10 18:00', 'Europe/Athens')->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-10 21:00', 'Europe/Athens')->utc()]);
    Todo::factory()->for($this->home)->create(['title' => 'Call the plumber', 'due_on' => '2026-11-10']);
    RecurringBill::factory()->for($this->home)->create(['name' => 'Internet', 'day_of_month' => 12]);
    $plant = Plant::factory()->for($this->home)->create(['name' => 'Basil', 'watering_interval_days' => 2]);
    $this->home->plantCareLogs()->create(['plant_id' => $plant->id, 'type' => 'watered', 'performed_at' => now()->subDays(3)]);

    $c = run('get_today_plan');

    expect($c['date'])->toBe('2026-11-10')
        ->and($c['weekday'])->toBe('Tuesday')
        ->and(array_column($c['chores_due_today'], 'name'))->toBe(['Wash towels'])
        ->and($c['events_today'][0])->toMatchArray(['title' => 'Parents visit', 'starts' => '2026-11-10 18:00'])
        ->and(array_column($c['todos_due'], 'title'))->toBe(['Call the plumber'])
        ->and(array_column($c['plants']['to_water'], 'name'))->toBe(['Basil'])
        ->and(array_column($c['bills_and_admin_next_3_days'], 'name'))->toBe(['Internet']);
});

test('log plant care and mark a chore done: confirmed, they work like the app', function () {
    $plant = Plant::factory()->for($this->home)->create(['name' => 'Pothos', 'water_amount_ml' => 250, 'watering_interval_days' => 7, 'location_type' => PlantLocation::Indoor]);
    $room = Room::factory()->for($this->home)->create();
    $chore = Chore::factory()->for($room)->create(['minutes' => 15]);

    $water = app(ToolRegistry::class)->call('log_plant_care', ['plant_id' => $plant->id, 'type' => 'watered'], $this->ctx);
    $done = app(ToolRegistry::class)->call('mark_chore_done', ['chore_id' => $chore->id], $this->ctx);

    app(Assistant::class)->confirm(AiPendingAction::findOrFail($water->pendingActionId), $this->user);
    app(Assistant::class)->confirm(AiPendingAction::findOrFail($done->pendingActionId), $this->user);

    expect($plant->careLogs()->first())->amount_ml->toBe(250)->user_id->toBe($this->user->id)
        ->and($plant->fresh()->next_watering_at->toDateString())->toBe('2026-11-17')
        ->and($chore->completions()->first())->minutes->toBe(15)->user_id->toBe($this->user->id);
});

test('add a to-do and tick off shopping, once confirmed', function () {
    $milk = ShoppingItem::factory()->for($this->home)->create(['name' => 'Milk']);

    foreach ([
        ['add_todo', ['title' => 'Book the boiler service', 'due_on' => '2026-11-14']],
        ['check_off_shopping_item', ['item_id' => $milk->id]],
    ] as [$tool, $input]) {
        $outcome = app(ToolRegistry::class)->call($tool, $input, $this->ctx);
        app(Assistant::class)->confirm(AiPendingAction::findOrFail($outcome->pendingActionId), $this->user);
    }

    expect(Todo::where('title', 'Book the boiler service')->first()->due_on->toDateString())->toBe('2026-11-14')
        ->and($milk->fresh()->checked)->toBeTrue();
});
