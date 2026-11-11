<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Briefing\BriefingService;
use App\Briefing\Recommender;
use App\Enums\BudgetPeriod;
use App\Enums\ExpenseCategory;
use App\Enums\HouseholdRole;
use App\Enums\PlantLocation;
use App\Enums\StockLevel;
use App\Enums\SupplyLevel;
use App\Jobs\DailyBriefing;
use App\Models\AdminItem;
use App\Models\Briefing;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Device;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\PlantCareLog;
use App\Models\RecommendationFeedback;
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
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeWeatherProvider;

/*
 * Days 38–41: the daily briefing (weather, calendar, tasks, shopping, money,
 * bills, plants, upkeep) and personalised recommendations.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 07:00', 'Europe/Athens')); // a Wednesday
    $this->weather = new FakeWeatherProvider(new WeatherForecast(9, 17, precipitationMm: 8, maxWindGustKmh: 30));
    app()->instance(WeatherProvider::class, $this->weather);

    $this->home = Household::factory()->create(['currency' => 'EUR', 'latitude' => 38.0, 'longitude' => 23.7]);
    $this->me = User::factory()->create(['name' => 'Maria']);
    $this->alex = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->home->addMember($this->alex);
    $this->room = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);
});

function brief(?User $user = null): array
{
    return app(BriefingService::class)->build(test()->home, $user ?? test()->me, HomeTime::today());
}

/** A balcony basil last watered three days ago, every two days: due today (and the rain will do it) */
function thirstyBasil(): Plant
{
    $basil = Plant::factory()->for(test()->home)->create(['name' => 'Basil', 'location_type' => PlantLocation::Balcony, 'watering_interval_days' => 2, 'minimum_temperature_c' => 5]);
    PlantCareLog::factory()->create(['plant_id' => $basil->id, 'performed_at' => now()->subDays(3)]);
    $basil->refreshCareDates();

    return $basil;
}

function overdueChore(string $name, int $lastDoneDaysAgo, ?User $assignee = null, ?User $doneBy = null): Chore
{
    $chore = Chore::factory()->for(test()->home)->create(['room_id' => test()->room->id, 'name' => $name, 'every_days' => 7, 'assignee_id' => $assignee?->id]);
    ChoreCompletion::factory()->create(['chore_id' => $chore->id, 'user_id' => $doneBy?->id, 'done_at' => now()->subDays($lastDoneDaysAgo)]);

    return $chore;
}

test('the briefing covers every part of the household, from the same tools as the assistant', function () {
    Event::factory()->for($this->home)->create(['title' => 'Dentist', 'starts_at' => '2026-11-11 08:30:00', 'ends_at' => '2026-11-11 09:30:00']);
    Event::factory()->for($this->home)->create(['title' => 'Parents visit', 'starts_at' => '2026-11-12 16:00:00', 'ends_at' => '2026-11-12 19:00:00']);
    Todo::factory()->for($this->home)->create(['title' => 'Call the plumber', 'due_on' => '2026-11-10']);
    overdueChore('Clean the oven', 12, assignee: $this->me);
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Milk', 'checked' => false, 'price' => 1.2]);
    InventoryItem::factory()->for($this->home)->create(['name' => 'Yoghurt', 'expires_on' => '2026-11-12', 'level' => StockLevel::Full]);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-10', 'amount' => 23.4, 'category' => ExpenseCategory::Groceries]);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-02', 'amount' => 100, 'category' => ExpenseCategory::Fun]);
    RecurringBill::factory()->for($this->home)->create(['name' => 'Internet', 'amount' => 32, 'day_of_month' => 13]);
    AdminItem::factory()->for($this->home)->create(['title' => 'Car insurance', 'kind' => 'renewal', 'due_on' => '2026-11-20', 'amount' => 310]);
    Supply::factory()->for($this->home)->create(['name' => 'Bin bags', 'level' => SupplyLevel::Out]);
    thirstyBasil();

    $b = brief();

    expect($b['for'])->toBe('Maria')->and($b['weekday'])->toBe('Wednesday')
        ->and($b['weather'])->toMatchArray(['minimum_temperature_c' => 9.0, 'maximum_temperature_c' => 17.0, 'precipitation_mm' => 8.0])
        ->and(array_column($b['calendar']['today'], 'title'))->toBe(['Dentist'])
        ->and(array_column($b['calendar']['tomorrow'], 'title'))->toBe(['Parents visit'])
        ->and(array_column($b['tasks']['todos'], 'title'))->toBe(['Call the plumber'])
        ->and($b['tasks']['chores_overdue'][0])->toMatchArray(['name' => 'Clean the oven', 'days_overdue' => 5, 'yours' => true])
        ->and($b['shopping'])->toMatchArray(['count' => 1, 'estimated_total' => 1.2])
        ->and(array_column($b['kitchen']['expiring'], 'name'))->toBe(['Yoghurt'])
        ->and($b['money'])->toMatchArray(['spent_yesterday' => 23.4, 'month_to_date' => 123.4])
        ->and(array_column($b['bills'], 'name'))->toBe(['Internet'])
        ->and(array_column($b['maintenance']['due'], 'title'))->toBe(['Car insurance'])
        ->and($b['plants']['to_water'][0])->toMatchArray(['name' => 'Basil', 'skip_for_rain' => true])
        ->and($b['unavailable'])->toBe([])
        ->and($b['headline'])->toBe('2 things due · Dentist at 10:30 · Internet due Fri 13 · 9–17 °C, rain');

    $text = BriefingService::text($b);
    expect($text)->toStartWith("Good morning, Maria. Wednesday 11 November.\n2 things due")
        ->toContain('- 1 item on the list, about €1.20')
        ->toContain("Calendar\n- 2026-11-11 10:30: Dentist")
        ->toContain('- Tomorrow 2026-11-12 18:00: Parents visit')
        ->toContain('- Overdue: Kitchen: Clean the oven, 5 days late (yours)')
        ->toContain('- Use soon: Yoghurt')
        ->toContain('- This month so far: €123.40')
        ->toContain('- Internet €32.00 on Fri 13 Nov')
        ->toContain('- Basil: skip watering, rain is coming')
        ->toContain('- Car insurance (renewal) due Fri 20 Nov')
        ->toContain('- Bin bags is out');
});

test('a section whose source fails is left out and named, never guessed', function () {
    $this->weather->down = true;

    $b = brief();

    expect($b['weather'])->toBeNull()
        ->and($b['weather_note'])->toBe('The weather service did not answer. Try again later.')
        ->and(BriefingService::text($b))->not->toContain('Weather');
});

test('recommendations: concrete, with a reason and a question for the assistant, most important first', function () {
    $this->home->budgets()->create(['period' => BudgetPeriod::Month, 'category' => null, 'amount' => 200]);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-05', 'amount' => 400, 'category' => ExpenseCategory::EatingOut]);
    InventoryItem::factory()->for($this->home)->create(['name' => 'Spinach', 'expires_on' => '2026-11-12', 'level' => StockLevel::Full]);
    RecurringBill::factory()->for($this->home)->create(['name' => 'Rent', 'amount' => 620, 'day_of_month' => 13]);
    Supply::factory()->for($this->home)->create(['name' => 'Bin bags', 'level' => SupplyLevel::Out]);
    thirstyBasil();

    $recs = brief()['recommendations'];

    expect(array_column($recs, 'rule'))->toBe(['budget_pace', 'rain_watering', 'expiring_food', 'bill_due', 'supplies_out'])
        ->and($recs[0]['key'])->toBe('budget_pace:2026-11')
        ->and($recs[0]['reason'])->toBe('you have spent €400.00 by the 11th, most on eating out')
        ->and($recs[2])->toMatchArray(['title' => 'Use Spinach first', 'ask' => 'Suggest a dinner that uses Spinach.'])
        ->and($recs[3]['title'])->toBe('Rent €620.00 is due Friday 13 Nov');
});

test('chores are suggested to the person they belong to: assigned, or the one who usually does them', function () {
    overdueChore('Mop floor', 20, doneBy: $this->alex);
    $oven = overdueChore('Clean the oven', 10, doneBy: $this->me);

    $mine = collect(brief($this->me)['recommendations'])->firstWhere('rule', 'overdue_chore');
    $alexs = collect(brief($this->alex)['recommendations'])->firstWhere('rule', 'overdue_chore');

    expect($mine['title'])->toBe('Kitchen: Clean the oven (3 days late)')->and($mine['reason'])->toBe('you usually do this one')
        ->and($mine['key'])->toStartWith("overdue_chore:{$oven->id}:")
        ->and($alexs['title'])->toBe('Kitchen: Mop floor (13 days late)');

    $oven->update(['assignee_id' => $this->alex->id]);
    expect(collect(brief($this->alex)['recommendations'])->firstWhere('rule', 'overdue_chore')['reason'])->toBe('it is assigned to you');
});

test('feedback: "not now" hides one for a week; a rule you keep dismissing sinks below one you find useful', function () {
    InventoryItem::factory()->for($this->home)->create(['name' => 'Spinach', 'expires_on' => '2026-11-12']);
    Supply::factory()->for($this->home)->create(['name' => 'Bin bags', 'level' => SupplyLevel::Out]);
    Sanctum::actingAs($this->me);
    $url = "/api/households/{$this->home->id}/recommendations";

    $first = $this->getJson($url)->assertOk()->json('data');
    expect(array_column($first, 'rule'))->toBe(['expiring_food', 'supplies_out']);

    $this->postJson("{$url}/feedback", ['key' => $first[0]['key'], 'verdict' => 'dismissed'])
        ->assertOk()->assertJsonPath('data.hidden_until', '2026-11-18');
    $this->postJson("{$url}/feedback", ['key' => 'nonsense', 'verdict' => 'dismissed'])->assertUnprocessable();
    expect(array_column($this->getJson($url)->json('data'), 'rule'))->toBe(['supplies_out'])
        // Alex didn't dismiss anything
        ->and(array_column(brief($this->alex)['recommendations'], 'rule'))->toBe(['expiring_food', 'supplies_out']);

    // A week later it's back, but two more dismissals of that kind (and a "useful" on the other) reorder them
    $this->travel(8)->days();
    RecommendationFeedback::forceCreate(['household_id' => $this->home->id, 'user_id' => $this->me->id, 'key' => 'expiring_food:old', 'rule' => 'expiring_food', 'verdict' => 'dismissed']);
    $this->postJson("{$url}/feedback", ['key' => 'supplies_out:Bin bags', 'verdict' => 'useful'])->assertOk();
    InventoryItem::query()->update(['expires_on' => '2026-11-20']);
    expect(array_column($this->getJson($url)->json('data'), 'rule'))->toBe(['supplies_out', 'expiring_food']);
});

test('every morning: each member\'s briefing is saved and its headline pushed; the API and the assistant read it', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'r1']]])]);
    Device::forceCreate(['user_id' => $this->me->id, 'expo_token' => 'ExponentPushToken[maria]', 'platform' => 'ios']);
    Todo::factory()->for($this->home)->create(['title' => 'Call the plumber', 'due_on' => '2026-11-11']);

    dispatch(new DailyBriefing($this->home, '2026-11-11'));

    $saved = Briefing::where('user_id', $this->me->id)->sole();
    expect(Briefing::count())->toBe(2)
        ->and($saved->pushed)->toBeTrue()
        ->and($saved->text)->toContain('To-do: Call the plumber')
        ->and(Briefing::where('user_id', $this->alex->id)->sole()->pushed)->toBeFalse();
    $pushes = Http::recorded()->flatMap(fn ($pair) => $pair[0]->data())->values()->all();
    expect($pushes)->toHaveCount(1)
        ->and($pushes[0]['body'])->toBe($saved->content['headline'])
        ->and((array) $pushes[0]['data'])->toBe(['url' => '/briefing']);

    Sanctum::actingAs($this->me);
    $this->travel(1)->days();
    $this->getJson("/api/households/{$this->home->id}/briefing?date=2026-11-11")->assertOk()
        ->assertJsonPath('text', $saved->text)->assertJsonPath('data.date', '2026-11-11');
    $this->getJson("/api/households/{$this->home->id}/briefing")->assertOk()->assertJsonPath('data.date', '2026-11-12')->assertJsonPath('sent_at', null);
    $this->getJson("/api/households/{$this->home->id}/briefing?date=2026-11-01")->assertNotFound();

    $tool = app(ToolRegistry::class)->call('get_daily_briefing', [], new ToolContext($this->home, $this->me, HomeTime::today()));
    expect($tool->content['briefing'])->toStartWith('Good morning, Maria. Thursday 12 November.');
});

test('a household without a location gets a briefing without weather, and the reason', function () {
    $this->home->update(['latitude' => null, 'longitude' => null]);

    $b = brief();

    expect($b['weather'])->toBeNull()->and($b['weather_note'])->toContain('no location')
        ->and($this->weather->calls)->toBe(0)
        ->and(app(Recommender::class))->toBeInstanceOf(Recommender::class);
});
