<?php

use App\Ai\Assistant;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Models\AiPendingAction;
use App\Models\Expense;
use App\Models\ShoppingItem;
use App\Models\User;
use App\Support\HomeTime;
use Database\Seeders\DemoSeeder;
use Tests\Support\ScriptedChatModel;

/*
 * Day 28: whole conversations across several modules, on the demo household
 * (six months of data up to today). The model is scripted to call the tools
 * a real model would; what is checked is everything else: that the tools
 * answer from the right rows, that a change waits for Confirm and then shows
 * up in the next answer, and that the history carries a follow-up.
 *
 * `php artisan ai:eval` runs the same kind of conversation against the real
 * model (docs/assistant.md#evaluating-it).
 */

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->owner = User::where('email', 'demo@homehelper.test')->sole();
    $this->home = $this->owner->households()->sole();
});

test('"What should I do today?" then "and the money?": one conversation over chores, plants, calendar, bills and spending', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['get_today_plan' => [], 'get_weather_forecast' => []]),
        fn (ChatRequest $r) => ChatReply::text('Water the plants, then the overdue oven.'),
        ScriptedChatModel::calls(['get_spending_summary' => [], 'get_upcoming_bills' => ['days' => 14]]),
        ChatReply::text('You are on track.'),
    ]);
    $conversation = app(Assistant::class)->start($this->home, $this->owner);

    app(Assistant::class)->send($conversation, $this->owner, 'What should I do today?');
    $today = $model->lastToolResults()['get_today_plan']['content'];

    expect($today['date'])->toBe(HomeTime::today()->toDateString())
        ->and(collect($today['todos_due'])->every(fn ($t) => $t['due_on'] <= $today['date']))->toBeTrue()
        ->and(collect($today['chores_overdue'])->pluck('name'))->toContain('Clean the oven') // the demo's slipping chore
        ->and($today['plants']['to_water'])->not->toBeEmpty(); // one plant is due today, one is late

    app(Assistant::class)->send($conversation, $this->owner, 'And how is the money looking?');
    $spending = $model->lastToolResults()['get_spending_summary']['content'];

    $monthStart = HomeTime::today()->startOfMonth()->toDateString();
    $expected = round((float) Expense::whereBelongsTo($this->home)->whereBetween('date', [$monthStart, HomeTime::today()->toDateString()])->sum('amount'), 2);
    expect($spending['total'])->toBe($expected)
        ->and($spending['currency'])->toBe('EUR');

    // The follow-up was sent with the first exchange in front of it
    expect(collect($model->requests[2]->transcript)->where('role', 'user')->map->text()->values()->all())
        ->toBe(['What should I do today?', 'And how is the money looking?']);
});

test('"What do I need to buy?" → "add the coffee" → Confirm → the list has it on the next question', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['get_shopping_list' => []]),
        ChatReply::text('Bananas, milk… and coffee is running out.'),
        fn (ChatRequest $r) => ScriptedChatModel::calls(['add_shopping_item' => ['name' => 'Ground coffee', 'category' => 'drinks', 'quantity' => '2']]),
        ChatReply::text('I can add 2 ground coffee. Confirm below.'),
        ScriptedChatModel::calls(['get_shopping_list' => []]),
        ChatReply::text('Ground coffee is on the list now.'),
    ]);
    $conversation = app(Assistant::class)->start($this->home, $this->owner);

    app(Assistant::class)->send($conversation, $this->owner, 'What do I need to buy?');
    $list = $model->lastToolResults()['get_shopping_list']['content'];
    expect(array_column($list['items'], 'name'))->toEqualCanonicalizing(
        ShoppingItem::whereBelongsTo($this->home)->where('checked', false)->pluck('name')->all()
    );

    $turn = app(Assistant::class)->send($conversation, $this->owner, 'Add two packs of coffee');
    expect(ShoppingItem::where('name', 'Ground coffee')->exists())->toBeFalse();
    app(Assistant::class)->confirm($turn['actions'][0], $this->owner);

    app(Assistant::class)->send($conversation, $this->owner, 'What\'s on the list now?');
    $after = $model->lastToolResults()['get_shopping_list']['content'];
    expect(collect($after['items'])->firstWhere('name', 'Ground coffee'))->toMatchArray(['quantity' => '2', 'added_by' => $this->owner->name]);

    // The model heard about the confirmation from Home Helper before the last question
    expect(collect($model->requests[4]->transcript)->firstWhere('role', 'event')->text())
        ->toBe('The person confirmed "Add "Ground coffee" (2) to the shopping list". It is done.');
});

test('"Which plants need attention?" → "I watered the late one" → its next watering moves', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['get_plant_care_today' => [], 'list_plant_alerts' => []]),
        fn (ChatRequest $r) => ChatReply::text('One is late.'),
    ]);
    $conversation = app(Assistant::class)->start($this->home, $this->owner);
    app(Assistant::class)->send($conversation, $this->owner, 'Which plants need attention?');

    $care = $model->lastToolResults()['get_plant_care_today']['content'];
    $late = collect($care['watering'])->sortByDesc('overdue_days')->first();
    expect($late['overdue_days'])->toBeGreaterThan(0);

    ScriptedChatModel::bind([
        ScriptedChatModel::calls(['log_plant_care' => ['plant_id' => $late['plant_id'], 'type' => 'watered']]),
        ChatReply::text('Confirm below.'),
    ]);
    $turn = app(Assistant::class)->send($conversation, $this->owner, 'I just watered it');
    app(Assistant::class)->confirm($turn['actions'][0], $this->owner);

    expect(AiPendingAction::sole()->status)->toBe('confirmed')
        ->and($this->home->plants()->findOrFail($late['plant_id'])->next_watering_at->isFuture())->toBeTrue();
});

test('a member asking gets the household\'s shared answers, and nobody\'s health logs exist as a tool', function () {
    $alex = User::where('email', 'alex@homehelper.test')->sole();
    $model = ScriptedChatModel::bind([ScriptedChatModel::calls(['list_overdue_chores' => []]), ChatReply::text('ok')]);

    app(Assistant::class)->send(app(Assistant::class)->start($this->home, $alex), $alex, 'What tasks are overdue?');

    expect($model->lastToolResults()['list_overdue_chores']['content']['overdue'])->not->toBeEmpty()
        ->and($model->requests[0]->context)->toContain('the household\'s member')
        ->and(implode(' ', array_column($model->requests[0]->tools, 'name')))->not->toMatch('/sleep|weight|food|water_entr|workout/');
});

test('ai:eval with the offline model: every deliverable question, and the memory ones, answered from the right tools', function () {
    $this->artisan('ai:eval', ['--provider' => 'offline'])
        ->expectsOutputToContain('15/15 passed')
        ->assertSuccessful();
});
