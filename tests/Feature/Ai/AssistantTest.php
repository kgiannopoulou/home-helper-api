<?php

use App\Ai\AiUnavailable;
use App\Ai\Assistant;
use App\Ai\AssistantRefused;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\ShoppingItem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ScriptedChatModel;

/*
 * The conversation loop (days 16, 20): model → tools → model, everything
 * stored, the whole history sent back next turn.
 */

beforeEach(function () {
    $this->home = Household::factory()->create(['name' => 'Patission 12', 'currency' => 'EUR']);
    $this->user = User::factory()->create(['name' => 'Maria']);
    $this->home->addMember($this->user, HouseholdRole::Owner);
});

function assistant(): Assistant
{
    return app(Assistant::class);
}

test('a question, a tool call, an answer: all stored in order, with token counts', function () {
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Milk']);
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['get_shopping_list' => []]),
        fn (ChatRequest $r) => ChatReply::text('Just milk.', 'scripted-model'),
    ]);
    $conversation = assistant()->start($this->home, $this->user);

    $turn = assistant()->send($conversation, $this->user, 'What do I need to buy?');

    expect(array_column(array_map(fn ($m) => $m->toArray(), $turn['messages']), 'role'))->toBe(['user', 'assistant', 'tool_results', 'assistant'])
        ->and($turn['messages'][3]->text())->toBe('Just milk.')
        ->and($turn['messages'][1]->input_tokens)->toBe(100)
        ->and($conversation->fresh()->title)->toBe('What do I need to buy?');

    // The second model call saw the tool's result
    expect($model->lastToolResults()['get_shopping_list']['content']['items'][0]['name'])->toBe('Milk');
});

test('the request carries the instructions, today\'s context and only this household\'s tools', function () {
    $this->travelTo(now()->setDate(2026, 11, 10)->setTime(10, 0));
    $model = ScriptedChatModel::bind([ChatReply::text('Hi.')]);

    assistant()->send(assistant()->start($this->home, $this->user), $this->user, 'hello');

    $request = $model->requests[0];
    expect($request->instructions)->toBe(Assistant::INSTRUCTIONS)
        ->and($request->context)->toContain('Household: Patission 12. Currency: EUR.')
        ->and($request->context)->toContain('You are talking to Maria, the household\'s owner.')
        ->and($request->context)->toContain('Today is Tuesday 10 November 2026')
        ->and(array_column($request->tools, 'name'))->toContain('get_today_plan', 'add_shopping_item');
});

test('the next turn sends the whole conversation, so follow-ups work', function () {
    $model = ScriptedChatModel::bind([ChatReply::text('Milk.'), ChatReply::text('Two litres.')]);
    $conversation = assistant()->start($this->home, $this->user);

    assistant()->send($conversation, $this->user, 'What do I need to buy?');
    assistant()->send($conversation, $this->user, 'How much of it?');

    $second = $model->requests[1]->transcript;
    expect(array_map(fn ($m) => [$m->role, $m->text()], $second))->toBe([
        ['user', 'What do I need to buy?'],
        ['assistant', 'Milk.'],
        ['user', 'How much of it?'],
    ]);
});

test('several tools in one reply all run, and each gets its own result', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['get_shopping_list' => [], 'list_overdue_chores' => [], 'list_todos' => ['status' => 'nope']]),
        ChatReply::text('Done.'),
    ]);

    assistant()->send(assistant()->start($this->home, $this->user), $this->user, 'Everything please');

    $results = $model->lastToolResults();
    expect(array_keys($results))->toBe(['get_shopping_list', 'list_overdue_chores', 'list_todos'])
        ->and($results['list_todos']['is_error'])->toBeTrue()
        ->and($results['get_shopping_list']['is_error'])->toBeFalse();
});

test('a reply cut off mid-call: its tool calls get an error result, so the history stays valid', function () {
    ScriptedChatModel::bind([
        new ChatReply([['type' => 'tool_call', 'id' => 'c1', 'name' => 'add_todo', 'input' => ['title' => 'x']]], 'max_tokens', 'm'),
    ]);
    $conversation = assistant()->start($this->home, $this->user);

    assistant()->send($conversation, $this->user, 'Add a to-do');

    $results = $conversation->messages()->where('role', 'tool_results')->first()->content['results'];
    expect($results[0])->toMatchArray(['tool_call_id' => 'c1', 'is_error' => true])
        ->and($conversation->pendingActions()->count())->toBe(0);
});

test('it stops after the round limit and says so', function () {
    config(['homehelper.ai.max_tool_rounds' => 2]);
    ScriptedChatModel::bind([ScriptedChatModel::calls(['list_todos' => []]), ScriptedChatModel::calls(['list_todos' => []])]);
    $conversation = assistant()->start($this->home, $this->user);

    assistant()->send($conversation, $this->user, 'Loop forever');

    expect($conversation->messages()->get()->last())->role->toBe('event')
        ->and($conversation->messages()->get()->last()->text())->toContain('stopped after 2 rounds');
});

test('a proposed change shows up as an action of this turn', function () {
    ScriptedChatModel::bind([
        ScriptedChatModel::calls(['add_shopping_item' => ['name' => 'Oat milk', 'category' => 'drinks']]),
        ChatReply::text('I can add oat milk. Confirm below.'),
    ]);

    $turn = assistant()->send(assistant()->start($this->home, $this->user), $this->user, 'Add oat milk');

    expect($turn['actions'])->toHaveCount(1)
        ->and($turn['actions'][0]->summary)->toBe('Add "Oat milk" to the shopping list')
        ->and(ShoppingItem::count())->toBe(0);
});

test('when the model is down, the person\'s message is kept', function () {
    ScriptedChatModel::bind([fn () => throw new AiUnavailable('down')]);
    $conversation = assistant()->start($this->home, $this->user);

    expect(fn () => assistant()->send($conversation, $this->user, 'Still there?'))->toThrow(AiUnavailable::class);
    expect($conversation->messages()->pluck('role')->all())->toBe(['user']);
});

test('a second message while one is being answered is refused', function () {
    ScriptedChatModel::bind([ChatReply::text('ok')]);
    $conversation = assistant()->start($this->home, $this->user);
    $lock = Cache::lock("ai:conversation:{$conversation->id}", 60);
    $lock->get();

    expect(fn () => assistant()->send($conversation, $this->user, 'hi'))
        ->toThrow(fn (AssistantRefused $e) => expect($e->status)->toBe(409)->and($e->errorCode)->toBe('conversation_busy'));
    $lock->release();
});

test('the daily message limit counts across conversations', function () {
    config(['homehelper.ai.daily_messages' => 2]);
    ScriptedChatModel::bind([ChatReply::text('1'), ChatReply::text('2')]);

    assistant()->send(assistant()->start($this->home, $this->user), $this->user, 'one');
    assistant()->send(assistant()->start($this->home, $this->user), $this->user, 'two');

    expect(fn () => assistant()->send(assistant()->start($this->home, $this->user), $this->user, 'three'))
        ->toThrow(fn (AssistantRefused $e) => expect($e->status)->toBe(429)->and($e->errorCode)->toBe('daily_limit_reached'));
});

test('a full conversation asks for a new one', function () {
    config(['homehelper.ai.max_messages' => 2]);
    ScriptedChatModel::bind([ChatReply::text('ok')]);
    $conversation = assistant()->start($this->home, $this->user);
    assistant()->send($conversation, $this->user, 'one');

    expect(fn () => assistant()->send($conversation, $this->user, 'two'))
        ->toThrow(fn (AssistantRefused $e) => expect($e->errorCode)->toBe('conversation_full'));
});
