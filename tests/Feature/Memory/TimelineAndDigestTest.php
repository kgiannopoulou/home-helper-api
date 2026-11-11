<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\ExpenseCategory;
use App\Enums\FactKind;
use App\Enums\HouseholdRole;
use App\Enums\PlantCareType;
use App\Jobs\MonthlyDigest;
use App\Jobs\WeeklyDigest;
use App\Memory\HouseholdDigest;
use App\Models\AdminItem;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Event;
use App\Models\Expense;
use App\Models\Household;
use App\Models\HouseholdSummary;
use App\Models\JobRun;
use App\Models\MemoryChunk;
use App\Models\Plant;
use App\Models\PlantCareLog;
use App\Models\Room;
use App\Models\ShoppingTrip;
use App\Models\Todo;
use App\Models\User;
use App\Queries\TimelineQuery;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * Day 36: the activity timeline, one UNION ALL over every module.
 * Day 37: weekly and monthly household summaries, written on schedule into memory.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Europe/Athens'));
    $this->home = Household::factory()->create(['currency' => 'EUR']);
    $this->me = User::factory()->create(['name' => 'Maria']);
    $this->alex = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->home->addMember($this->alex);
    $room = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);
    $this->oven = Chore::factory()->for($this->home)->create(['room_id' => $room->id, 'name' => 'Clean the oven', 'every_days' => 14]);
    $this->monstera = Plant::factory()->for($this->home)->create(['name' => 'Monstera']);
});

function timeline(array $args = []): array
{
    $t = test();

    return (new TimelineQuery($t->home, HomeTime::today(), $args['viewer'] ?? $t->me,
        $args['from'] ?? HomeTime::today()->subDays(30), $args['to'] ?? HomeTime::today(),
        $args['kinds'] ?? null, $args['search'] ?? null, $args['limit'] ?? 50))->get();
}

test('every module in one timeline, newest first, at home time', function () {
    Expense::factory()->for($this->home)->create(['date' => '2026-11-09', 'amount' => 42.5, 'category' => ExpenseCategory::Groceries, 'note' => 'Market', 'user_id' => $this->me->id]);
    ShoppingTrip::factory()->for($this->home)->create(['date' => '2026-11-08', 'store' => 'Lidl', 'total' => 61.2, 'item_count' => 12]);
    ChoreCompletion::factory()->create(['chore_id' => $this->oven->id, 'user_id' => $this->alex->id, 'done_at' => '2026-11-10 21:30:00', 'minutes' => 40]); // 23:30 at home
    Todo::factory()->for($this->home)->create(['title' => 'Descale the kettle', 'done_at' => '2026-11-07 08:00:00']);
    PlantCareLog::factory()->create(['plant_id' => $this->monstera->id, 'type' => PlantCareType::Watered, 'amount_ml' => 350, 'performed_at' => '2026-11-10 06:00:00', 'user_id' => $this->me->id]);
    Event::factory()->for($this->home)->create(['title' => 'Dentist', 'starts_at' => '2026-11-06 15:00:00', 'ends_at' => '2026-11-06 16:00:00', 'location' => 'Kypseli']);
    AdminItem::factory()->for($this->home)->create(['title' => 'Car insurance', 'kind' => 'renewal', 'amount' => 310, 'done_at' => '2026-11-05 09:00:00']);
    $this->home->facts()->create(['user_id' => $this->me->id, 'kind' => FactKind::Routine, 'content' => 'Bins on Tuesday']);
    Todo::factory()->for($this->home)->create(['title' => 'Not done yet']);
    Expense::factory()->for($this->home)->create(['date' => '2026-09-01']); // outside the window

    $rows = timeline();

    expect(array_column($rows, 'kind'))->toBe(['fact', 'chore', 'plant_care', 'expense', 'shopping_trip', 'todo', 'event', 'admin'])
        ->and($rows[1])->toBe(['kind' => 'chore', 'title' => 'Kitchen: Clean the oven', 'detail' => '40 min', 'amount' => null, 'who' => 'Alex', 'date' => '2026-11-10', 'time' => '23:30', 'id' => $rows[1]['id']])
        ->and($rows[2]['title'])->toBe('Watered Monstera')->and($rows[2]['detail'])->toBe('350 ml')
        ->and($rows[3])->toMatchArray(['title' => 'Market', 'detail' => 'groceries', 'amount' => 42.5, 'date' => '2026-11-09', 'time' => null, 'who' => 'Maria'])
        ->and($rows[4])->toMatchArray(['title' => 'Lidl', 'detail' => '12 items', 'amount' => 61.2])
        ->and($rows[6])->toMatchArray(['title' => 'Dentist', 'detail' => 'Kypseli', 'time' => '17:00']);
});

test('filters: kinds, a search word, a day range at home, and the limit', function () {
    // 23:30 on the 31st in Athens is 21:30 UTC: it belongs to the 31st, not the 1st
    ChoreCompletion::factory()->create(['chore_id' => $this->oven->id, 'done_at' => '2026-10-31 21:30:00']);
    ChoreCompletion::factory()->create(['chore_id' => $this->oven->id, 'done_at' => '2026-11-03 10:00:00']);
    PlantCareLog::factory()->count(3)->create(['plant_id' => $this->monstera->id, 'performed_at' => '2026-11-02 10:00:00']);

    expect(timeline(['kinds' => ['chore']]))->toHaveCount(2)
        ->and(timeline(['search' => 'monstera']))->toHaveCount(3)
        ->and(timeline(['search' => '100%_']))->toBe([])
        ->and(array_column(timeline(['from' => CarbonImmutable::parse('2026-11-01'), 'kinds' => ['chore']]), 'date'))->toBe(['2026-11-03'])
        ->and(array_column(timeline(['to' => CarbonImmutable::parse('2026-10-31'), 'kinds' => ['chore']]), 'date'))->toBe(['2026-10-31'])
        ->and(timeline(['limit' => 2]))->toHaveCount(2)
        ->and(fn () => timeline(['kinds' => ['nope']]))->toThrow(InvalidArgumentException::class);
});

test('private notes and facts, and assistant changes, are only in their own person\'s timeline', function () {
    $this->home->notes()->create(['user_id' => $this->me->id, 'title' => 'Present', 'body' => 'x', 'private' => true]);
    $this->home->facts()->create(['user_id' => $this->me->id, 'kind' => FactKind::Fact, 'content' => 'Shared one']);

    expect(array_column(timeline(), 'title'))->toEqualCanonicalizing(['Present', 'Shared one'])
        ->and(array_column(timeline(['viewer' => $this->alex]), 'title'))->toBe(['Shared one']);
});

test('the timeline over the API and as an assistant tool', function () {
    Todo::factory()->for($this->home)->create(['title' => 'Descale the kettle', 'done_at' => '2026-09-25 08:00:00']);
    Sanctum::actingAs($this->me);

    $this->getJson("/api/households/{$this->home->id}/timeline?from=2026-09-01&q=kettle")
        ->assertOk()->assertJsonPath('data.0.title', 'Descale the kettle')->assertJsonPath('data.0.date', '2026-09-25')
        ->assertJsonPath('kinds', TimelineQuery::kinds());
    $this->getJson("/api/households/{$this->home->id}/timeline?kinds[]=nope")->assertUnprocessable();

    $outcome = app(ToolRegistry::class)->call('get_activity_timeline', ['from' => '2026-01-01', 'search' => 'kettle'],
        new ToolContext($this->home, $this->me, HomeTime::today()));
    expect($outcome->content['entries'])->toBe([['kind' => 'todo', 'title' => 'Descale the kettle', 'detail' => 'done', 'amount' => null, 'who' => null, 'date' => '2026-09-25', 'time' => '11:00']])
        ->and($outcome->content['more_than_shown'])->toBeFalse();
});

test('a week in numbers and words, compared with the week before, and in long-term memory', function () {
    // Monday 2 – Sunday 8 November
    Expense::factory()->for($this->home)->create(['date' => '2026-11-03', 'amount' => 100, 'category' => ExpenseCategory::Groceries]);
    Expense::factory()->for($this->home)->create(['date' => '2026-11-08', 'amount' => 20, 'category' => ExpenseCategory::EatingOut]);
    Expense::factory()->for($this->home)->create(['date' => '2026-10-28', 'amount' => 150, 'category' => ExpenseCategory::Groceries]);
    ChoreCompletion::factory()->create(['chore_id' => $this->oven->id, 'user_id' => $this->alex->id, 'done_at' => '2026-11-04 18:00:00']);
    ChoreCompletion::factory()->create(['chore_id' => $this->oven->id, 'user_id' => $this->alex->id, 'done_at' => '2026-11-06 18:00:00']);
    ChoreCompletion::factory()->create(['chore_id' => $this->oven->id, 'user_id' => $this->me->id, 'done_at' => '2026-11-07 18:00:00']);
    PlantCareLog::factory()->create(['plant_id' => $this->monstera->id, 'performed_at' => '2026-11-05 09:00:00']);
    Event::factory()->for($this->home)->create(['title' => 'Dentist', 'starts_at' => '2026-11-06 15:00:00', 'ends_at' => '2026-11-06 16:00:00']);

    $summary = app(HouseholdDigest::class)->write($this->home, 'week', CarbonImmutable::parse('2026-11-05'));

    expect($summary->starts_on->toDateString())->toBe('2026-11-02')
        ->and($summary->ends_on->toDateString())->toBe('2026-11-08')
        // JSON gives 150 back for 150.0, so compare loosely
        ->and($summary->stats)->toEqual([...$summary->stats, 'spent' => 120.0, 'spent_previous' => 150.0, 'chores_done' => 3, 'chores_by' => ['Alex' => 2, 'Maria' => 1], 'plant_care' => 1, 'events' => ['Dentist']])
        ->and(array_keys($summary->stats))->toContain('chores_overdue_at_end', 'top_categories')
        ->and($summary->text)->toBe('The week of Mon 2 Nov to Sun 8 Nov 2026: spent €120.00 (groceries €100.00, eating out €20.00), 20% less than the week before (€150.00). 3 chores done (Alex 2, Maria 1), 0 overdue at the end; 0 to-dos done. Plants cared for 1 time. 0 shopping trips (€0.00). On the calendar: Dentist.')
        ->and(MemoryChunk::where('source_type', 'summary')->sole()->title)->toBe('Household summary: Week of Mon 2 Nov 2026');

    // Written again (a late expense): replaced, not duplicated
    Expense::factory()->for($this->home)->create(['date' => '2026-11-04', 'amount' => 30, 'category' => ExpenseCategory::Groceries]);
    app(HouseholdDigest::class)->write($this->home, 'week', CarbonImmutable::parse('2026-11-08'));
    expect(HouseholdSummary::count())->toBe(1)->and(HouseholdSummary::sole()->stats['spent'])->toEqual(150)
        ->and(MemoryChunk::where('source_type', 'summary')->count())->toBe(1);
});

test('the scheduled jobs write last week (on Monday) and last month (on the 1st), logged in job_runs', function () {
    Expense::factory()->for($this->home)->create(['date' => '2026-10-15', 'amount' => 80]);

    dispatch(new WeeklyDigest($this->home, '2026-11-09'));
    dispatch(new MonthlyDigest($this->home, '2026-11-01'));

    expect(HouseholdSummary::orderBy('period')->get()->map(fn ($s) => [$s->period, $s->starts_on->toDateString(), $s->ends_on->toDateString()])->all())
        ->toBe([['week', '2026-11-02', '2026-11-08'], ['month', '2026-10-01', '2026-10-31']]) // enum order
        ->and(HouseholdSummary::where('period', 'month')->sole()->text)->toStartWith('October 2026: spent €80.00')
        ->and(JobRun::where('job', 'MonthlyDigest')->sole()->summary['spent'])->toEqual(80);

    Sanctum::actingAs($this->me);
    $this->getJson("/api/households/{$this->home->id}/summaries?period=month")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'October 2026');
});
