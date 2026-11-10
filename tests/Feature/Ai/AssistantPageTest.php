<?php

use App\Ai\AiUnavailable;
use App\Ai\ChatReply;
use App\Models\AiConversation;
use App\Models\Household;
use App\Models\ShoppingItem;
use App\Models\User;
use App\Web\CurrentHousehold;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ScriptedChatModel;

/*
 * The chat page (days 18–19): Inertia posts, the conversation comes back as props.
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->user = User::factory()->create();
    $this->home->addMember($this->user);
    $this->actingAs($this->user)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
});

test('an empty page offers the questions it is built for', function () {
    $this->get('/assistant')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('assistant')
        ->where('conversation', null)
        ->where('messages', [])
        ->where('suggestions', fn ($s) => collect($s)->contains('What do I need to buy?') && collect($s)->contains('How much did I spend this month?'))
        ->has('provider'));
});

test('ask, get redirected to the conversation, see the answer and what it looked at', function () {
    ScriptedChatModel::bind([ScriptedChatModel::calls(['get_shopping_list' => []]), ChatReply::text('Nothing to buy.')]);

    $response = $this->post('/assistant', ['message' => 'What do I need to buy?']);
    $conversation = AiConversation::sole();
    $response->assertRedirect("/assistant/{$conversation->id}");

    $this->get("/assistant/{$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('conversation.id', $conversation->id)
        ->where('conversations.0.title', 'What do I need to buy?')
        ->has('messages', 2)
        ->where('messages.1.text', 'Nothing to buy.')
        ->where('messages.1.tools', ['Read the shopping list']));
});

test('confirm from the page', function () {
    ScriptedChatModel::bind([ScriptedChatModel::calls(['add_shopping_item' => ['name' => 'Eggs', 'category' => 'food']]), ChatReply::text('Confirm below.')]);
    $this->post('/assistant', ['message' => 'Add eggs']);
    $conversation = AiConversation::sole();
    $action = $conversation->pendingActions()->sole();

    $this->get("/assistant/{$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('actions.0.summary', 'Add "Eggs" to the shopping list')->where('actions.0.status', 'pending'));

    $this->post("/assistant/actions/{$action->id}/confirm")->assertRedirect("/assistant/{$conversation->id}");

    expect(ShoppingItem::where('name', 'Eggs')->exists())->toBeTrue();
    $this->get("/assistant/{$conversation->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('actions.0.status', 'confirmed')
        ->where('messages.2.role', 'event'));
});

test('when the model is down the page shows why, and keeps the question', function () {
    ScriptedChatModel::bind([fn () => throw new AiUnavailable('The assistant needs ANTHROPIC_API_KEY.', retryable: false)]);

    $this->post('/assistant', ['message' => 'Hello?'])->assertRedirect();

    $conversation = AiConversation::sole();
    expect($conversation->messages()->sole()->text())->toBe('Hello?');
});

test('someone else\'s conversation is not found', function () {
    $other = User::factory()->create();
    $this->home->addMember($other);
    $theirs = AiConversation::forceCreate(['household_id' => $this->home->id, 'user_id' => $other->id, 'title' => 'Theirs']);

    $this->get("/assistant/{$theirs->id}")->assertNotFound();
    $this->get('/assistant')->assertInertia(fn (AssertableInertia $page) => $page->where('conversations', []));
});
