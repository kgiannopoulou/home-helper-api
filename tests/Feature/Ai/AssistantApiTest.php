<?php

use App\Ai\AiUnavailable;
use App\Ai\ChatReply;
use App\Models\AiPendingAction;
use App\Models\Household;
use App\Models\ShoppingItem;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScriptedChatModel;

/*
 * The assistant over the API (day 16): what the phone calls.
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->user = User::factory()->create();
    $this->home->addMember($this->user);
    $this->base = "/api/households/{$this->home->id}/assistant";
});

test('start a conversation with a question and get the answer back', function () {
    Sanctum::actingAs($this->user);
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Milk']);
    ScriptedChatModel::bind([ScriptedChatModel::calls(['get_shopping_list' => []]), ChatReply::text('Just milk.')]);

    $response = $this->postJson("{$this->base}/conversations", ['message' => 'What do I need to buy?'])
        ->assertCreated()
        ->assertJsonPath('conversation.title', 'What do I need to buy?')
        ->assertJsonPath('messages.0', fn ($m) => $m['role'] === 'user' && $m['text'] === 'What do I need to buy?')
        ->assertJsonPath('messages.1.role', 'assistant')
        ->assertJsonPath('messages.1.tools', ['Read the shopping list'])
        ->assertJsonPath('messages.1.text', 'Just milk.')
        ->assertJsonPath('actions', []);

    // Tool results and the provider's raw blocks stay on the server
    expect($response->getContent())->not->toContain('tool_results')->not->toContain('"raw"');
});

test('continue it, read it back, list and delete it', function () {
    Sanctum::actingAs($this->user);
    ScriptedChatModel::bind([ChatReply::text('Hello.'), ChatReply::text('Bye.')]);

    $id = $this->postJson("{$this->base}/conversations", ['message' => 'Hi'])->json('conversation.id');
    $this->postJson("{$this->base}/conversations/{$id}/messages", ['message' => 'Thanks'])
        ->assertOk()->assertJsonCount(2, 'messages');

    $this->getJson("{$this->base}/conversations/{$id}")->assertOk()->assertJsonCount(4, 'messages');
    $this->getJson("{$this->base}/conversations")->assertOk()->assertJsonPath('data.0.id', $id);
    $this->deleteJson("{$this->base}/conversations/{$id}")->assertNoContent();
    $this->getJson("{$this->base}/conversations/{$id}")->assertNotFound();
});

test('confirm and decline over the API', function () {
    Sanctum::actingAs($this->user);
    ScriptedChatModel::bind([
        ScriptedChatModel::calls(['add_shopping_item' => ['name' => 'Eggs', 'category' => 'food'], 'add_todo' => ['title' => 'Fix tap']]),
        ChatReply::text('Confirm below.'),
    ]);

    $actions = $this->postJson("{$this->base}/conversations", ['message' => 'Eggs and the tap'])
        ->assertJsonCount(2, 'actions')->assertJsonPath('actions.0.status', 'pending')->json('actions');

    $this->postJson("{$this->base}/actions/{$actions[0]['id']}/confirm")->assertOk()->assertJsonPath('action.status', 'confirmed');
    $this->postJson("{$this->base}/actions/{$actions[1]['id']}/decline")->assertOk()->assertJsonPath('action.status', 'declined');
    $this->postJson("{$this->base}/actions/{$actions[0]['id']}/confirm")->assertConflict()->assertJsonPath('code', 'action_closed'); // once only

    expect(ShoppingItem::where('name', 'Eggs')->exists())->toBeTrue()
        ->and($this->home->todos()->count())->toBe(0);
});

test('conversations are private: another member sees 404, a non-member 403', function () {
    Sanctum::actingAs($this->user);
    ScriptedChatModel::bind([ScriptedChatModel::calls(['add_todo' => ['title' => 'Secret']]), ChatReply::text('ok')]);
    $created = $this->postJson("{$this->base}/conversations", ['message' => 'Private'])->json();
    $id = $created['conversation']['id'];
    $action = $created['actions'][0]['id'];

    $housemate = User::factory()->create();
    $this->home->addMember($housemate);
    Sanctum::actingAs($housemate);
    $this->getJson("{$this->base}/conversations/{$id}")->assertNotFound();
    $this->postJson("{$this->base}/conversations/{$id}/messages", ['message' => 'hi'])->assertNotFound();
    $this->postJson("{$this->base}/actions/{$action}/confirm")->assertNotFound();
    $this->getJson("{$this->base}/conversations")->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("{$this->base}/conversations")->assertForbidden();

    expect(AiPendingAction::findOrFail($action)->status)->toBe('pending');
});

test('the model being down is a 503 in the API error format, and the message is kept', function () {
    Sanctum::actingAs($this->user);
    ScriptedChatModel::bind([fn () => throw new AiUnavailable('The model is not reachable right now. Try again in a moment.')]);

    $this->postJson("{$this->base}/conversations", ['message' => 'Hello?'])
        ->assertStatus(503)
        ->assertJsonPath('code', 'ai_unavailable')
        ->assertJsonPath('retryable', true)
        ->assertJsonStructure(['message', 'code', 'request_id', 'conversation' => ['id']])
        ->assertHeader('X-Request-Id');
});

test('a message is required and has a length limit', function () {
    Sanctum::actingAs($this->user);
    $id = $this->postJson("{$this->base}/conversations")->assertCreated()->json('conversation.id');

    $this->postJson("{$this->base}/conversations/{$id}/messages", ['message' => ''])->assertUnprocessable();
    $this->postJson("{$this->base}/conversations/{$id}/messages", ['message' => str_repeat('a', 4001)])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
});

test('the tools endpoint lists what the assistant can do, and which changes need confirmation', function () {
    Sanctum::actingAs($this->user);

    $tools = collect($this->getJson("{$this->base}/tools")->assertOk()->json('data'))->keyBy('name');

    expect($tools['get_spending_summary'])->toMatchArray(['changes_data' => false, 'enabled' => true])
        ->and($tools['add_shopping_item'])->toMatchArray(['changes_data' => true, 'needs_confirmation' => true]);
});

test('sending is rate limited per person', function () {
    Sanctum::actingAs($this->user);
    ScriptedChatModel::bind(array_fill(0, 10, ChatReply::text('ok')));
    $id = $this->postJson("{$this->base}/conversations")->json('conversation.id');

    for ($i = 0; $i < 9; $i++) { // the create above counted as one
        $this->postJson("{$this->base}/conversations/{$id}/messages", ['message' => "m{$i}"])->assertOk();
    }
    $this->postJson("{$this->base}/conversations/{$id}/messages", ['message' => 'too many'])
        ->assertTooManyRequests()->assertJsonPath('code', 'too_many_requests');
});
