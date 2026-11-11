<?php

use App\Ai\Assistant;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Models\Expense;
use App\Models\HouseholdSummary;
use App\Models\PlantCareLog;
use App\Models\User;
use App\Support\HomeTime;
use App\Weather\WeatherProvider;
use Database\Seeders\DemoSeeder;
use Tests\Support\FakeWeatherProvider;
use Tests\Support\ScriptedChatModel;

/*
 * Day 42: can the assistant answer from history? On the demo household (six
 * months of data, notes, facts and the past summaries the seeder writes),
 * with the offline model doing the routing for real: question → tools →
 * answer, and the answer checked against the database.
 *
 * `php artisan ai:eval` asks the same kind of questions of the real model.
 */

beforeEach(function () {
    app()->instance(WeatherProvider::class, new FakeWeatherProvider);
    config(['homehelper.ai.provider' => 'offline']);
    $this->seed(DemoSeeder::class);
    $this->owner = User::where('email', 'demo@homehelper.test')->sole();
    $this->home = $this->owner->households()->sole();
});

function ask(string $question, $conversation = null): array
{
    $assistant = app(Assistant::class);
    $conversation ??= $assistant->start(test()->home, test()->owner);
    $turn = $assistant->send($conversation, test()->owner, $question);
    $assistantMessages = array_filter($turn['messages'], fn ($m) => $m->role === 'assistant');

    return [
        'answer' => trim(implode("\n", array_map(fn ($m) => $m->text(), $assistantMessages))),
        'tools' => array_values(array_unique(array_merge(...array_map(fn ($m) => array_column($m->toolCalls(), 'name'), $assistantMessages)))),
        'actions' => $turn['actions'],
        'conversation' => $conversation,
    ];
}

test('"When did we last descale the kettle?" — from the timeline, with the date', function () {
    $r = ask('When did we last descale the kettle?');

    expect($r['tools'])->toBe(['get_activity_timeline'])
        // The seeder writes it at 19:30 UTC, 47 days ago
        ->and($r['answer'])->toContain(now()->startOfDay()->subDays(47)->setTime(19, 30)->setTimezone(HomeTime::zone())->format('Y-m-d H:i').': Descale the kettle (Alex)');
});

test('"Who watered the Monstera last?" — the newest watering and who did it', function () {
    $last = PlantCareLog::query()->where('type', 'watered')->whereHas('plant', fn ($q) => $q->where('name', 'Monstera'))
        ->with('user')->latest('performed_at')->first();

    $r = ask('Who watered the Monstera last?');

    $at = $last->performed_at->setTimezone(HomeTime::zone());
    expect($r['answer'])->toContain($at->format('Y-m-d H:i').": Watered Monstera ({$last->user->name})");
});

test('"What did we decide about the summer holiday?" and "how do I fix low boiler pressure?" — from the notes', function () {
    $holiday = ask('What did we decide about the summer holiday?');
    $boiler = ask('How do I fix low pressure on the boiler?');

    expect($holiday['tools'])->toBe(['search_household_memory'])
        ->and($holiday['answer'])->toContain('Summer holiday: what we decided')->toContain('Naxos')
        ->and($boiler['answer'])->toStartWith("From your notes and memory:\n- Boiler: low pressure reset")->toContain('1.5 bar');
});

test('"How did last month go?" — the monthly summary, whose numbers are the database\'s', function () {
    $month = HomeTime::today()->subMonthNoOverflow();
    $spent = round((float) Expense::whereBelongsTo($this->home)
        ->whereBetween('date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])->sum('amount'), 2);

    $r = ask('How did last month go?');

    $summary = HouseholdSummary::where('period', 'month')->orderByDesc('starts_on')->first();
    expect($r['tools'])->toBe(['get_household_summaries'])
        ->and($summary->starts_on->toDateString())->toBe($month->startOfMonth()->toDateString())
        ->and($summary->stats['spent'])->toEqual($spent)
        ->and($r['answer'])->toStartWith($month->format('F Y').': spent €'.number_format($spent, 2));
});

test('"Remember that…" in one conversation, known in the next', function () {
    $r = ask('Remember that the plumber comes on Thursdays.');
    expect($r['tools'])->toBe(['remember_fact'])->and($r['actions'])->toHaveCount(1);

    app(Assistant::class)->confirm($r['actions'][0], $this->owner);
    $model = ScriptedChatModel::bind([fn (ChatRequest $req) => ChatReply::text('Thursdays.')]);
    app(Assistant::class)->send(app(Assistant::class)->start($this->home, $this->owner), $this->owner, 'When does the plumber come?');

    expect($model->requests[0]->context)->toContain('- Routine: The plumber comes on Thursdays [')
        // and the demo's own memory: Alex's diet, the rent, and the private note only for its author
        ->toContain('- Preference (about Alex): Doesn\'t eat meat; fish is fine.')
        ->toContain('(private)');
});

test('a model that searches its own way: words the notes don\'t use still find them, and nothing is invented when they don\'t', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['search_household_memory' => ['query' => 'heating not working pressure']]),
        ChatReply::text('ok'),
        ScriptedChatModel::calls(['search_household_memory' => ['query' => 'swimming pool cleaning schedule']]),
        ChatReply::text('ok'),
    ]);
    $conversation = app(Assistant::class)->start($this->home, $this->owner);

    app(Assistant::class)->send($conversation, $this->owner, 'The heating stopped, what now?');
    $heating = $model->lastToolResults()['search_household_memory']['content'];
    app(Assistant::class)->send($conversation, $this->owner, 'When do we clean the pool?');
    $pool = $model->lastToolResults()['search_household_memory']['content'];

    // "heating" is folded onto "boiler" by the local embedder; a real embedding model knows it anyway
    expect(collect($heating['hits'])->firstWhere('source', 'note')['title'])->toBe('Boiler: low pressure reset')
        // and the week it was written, whose summary lists it
        ->and(collect($heating['hits'])->pluck('passage')->implode(' '))->toContain('Notes added: Boiler: low pressure reset')
        ->and($pool['hits'])->toBe([])
        ->and($pool['note'])->toContain('Nothing in the household\'s memory matches');
});
