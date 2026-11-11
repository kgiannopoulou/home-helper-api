<?php

use App\Ai\Assistant;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Enums\HouseholdRole;
use App\Jobs\PruneVoiceConversations;
use App\Jobs\RememberConversations;
use App\Models\AiConversation;
use App\Models\Household;
use App\Models\MemoryChunk;
use App\Models\ShoppingItem;
use App\Models\User;
use App\Models\VoiceSettings;
use App\Voice\FakeVoice;
use App\Voice\VoiceTurns;
use App\Voice\WakeTicket;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScriptedChatModel;

/*
 * Days 46, 48, 53, 54, 55: the voice turn over the API with the fake engines:
 * what was heard, what was answered, what was spoken, and the controls
 * around it (cancel, confirm by voice, styles, privacy).
 */

beforeEach(function () {
    config(['homehelper.voice.driver' => 'fake', 'homehelper.voice.token' => 'secret', 'homehelper.ai.provider' => 'offline']);
    $this->home = Household::factory()->create();
    $this->me = User::factory()->create(['name' => 'Maria']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->url = "/api/households/{$this->home->id}/voice/turns";
    Sanctum::actingAs($this->me);
});

function say(string $words): UploadedFile
{
    return UploadedFile::fake()->createWithContent('speech.webm', "TEXT:{$words}");
}

test('speak a question: it is heard, answered for the ear, spoken, and playable for a few minutes', function () {
    $model = ScriptedChatModel::bind([
        ScriptedChatModel::calls(['get_shopping_list' => []]),
        ChatReply::text("To buy:\n- Milk\n- Bread"),
    ]);
    ShoppingItem::factory()->for($this->home)->create(['name' => 'Milk', 'checked' => false]);

    $r = $this->post($this->url, ['audio' => say('What do I need to buy?'), 'turn_id' => 't-1'], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('heard', 'What do I need to buy?')
        ->assertJsonPath('reply', "To buy:\n- Milk\n- Bread")
        ->assertJsonPath('spoken', 'To buy: Milk and Bread.')
        ->assertJsonPath('conversation.channel', 'voice')
        ->assertJsonPath('messages.1.tools', ['Read the shopping list'])
        ->assertJsonPath('cancelled', false)
        ->json();

    // The model was told it is speaking, in the person's style (conversational by default)
    expect($model->requests[0]->context)->toContain('This answer will be spoken aloud')->toContain('about 60 words')
        ->and(app(FakeVoice::class)->spoken)->toBe([['text' => 'To buy: Milk and Bread.', 'voice' => 'en_GB-alan-medium', 'rate' => 1.0]]);

    $this->get($r['audio_url'])->assertOk()->assertHeader('Content-Type', 'audio/wav')->assertHeader('Cache-Control', 'no-store, private');
    // A URL that wasn't signed, or another person, gets nothing
    $this->get(strtok($r['audio_url'], '?'))->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($r['audio_url'])->assertForbidden();
});

test('a follow-up continues the same spoken conversation, with the history', function () {
    $model = ScriptedChatModel::bind([ChatReply::text('Milk.'), ChatReply::text('Two litres.')]);
    $first = $this->post($this->url, ['audio' => say('What do I need?'), 'turn_id' => 't-1'], ['Accept' => 'application/json'])->json();

    $this->post($this->url, ['audio' => say('How much?'), 'turn_id' => 't-2', 'conversation_id' => $first['conversation']['id']], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('reply', 'Two litres.');

    expect(collect($model->requests[1]->transcript)->where('role', 'user')->map->text()->values()->all())->toBe(['What do I need?', 'How much?'])
        ->and(AiConversation::count())->toBe(1);
});

test('silence: "I didn\'t catch that", spoken, and no conversation is started', function () {
    $this->post($this->url, ['audio' => UploadedFile::fake()->createWithContent('s.webm', "\0\0\0"), 'turn_id' => 't-1'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('heard', '')->assertJsonPath('reply', VoiceTurns::NOT_HEARD)
        ->assertJsonPath('conversation', null);

    expect(AiConversation::count())->toBe(0);
});

test('the browser can send what it heard as text; with VOICE_DRIVER=browser it speaks the answer itself', function () {
    config(['homehelper.voice.driver' => 'browser']);
    ScriptedChatModel::bind([ChatReply::text('It is €12.40.')]);

    $this->postJson($this->url, ['text' => 'How much was the market?', 'turn_id' => 't-1'])
        ->assertOk()->assertJsonPath('spoken', 'It is 12 euros 40.')->assertJsonPath('audio_url', null);

    // Without a service there is nothing to send audio to
    $this->post($this->url, ['audio' => say('hi'), 'turn_id' => 't-2'], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('code', 'audio_unusable');
});

test('speech to text down: 503 and nothing asked; text to speech down: the answer still comes, without audio', function () {
    $fake = app(FakeVoice::class);
    $fake->down = true;
    $this->post($this->url, ['audio' => say('Hello'), 'turn_id' => 't-1'], ['Accept' => 'application/json'])
        ->assertStatus(503)->assertJsonPath('code', 'voice_unavailable');
    expect(AiConversation::count())->toBe(0);

    $fake->down = false;
    ScriptedChatModel::bind([fn (ChatRequest $r) => tap(ChatReply::text('Hi.'), fn () => app(FakeVoice::class)->down = true)]);
    $this->postJson($this->url, ['text' => 'Hello', 'turn_id' => 't-2'])->assertOk()
        ->assertJsonPath('reply', 'Hi.')->assertJsonPath('audio_url', null);
});

test('interrupting a turn stops it at its next step; the next turn knows the answer never came', function () {
    $model = ScriptedChatModel::bind([
        // While the model "thinks", the person says stop
        function (ChatRequest $r) {
            app(VoiceTurns::class)->cancel(test()->me, 't-1');

            return ScriptedChatModel::calls(['get_shopping_list' => []]);
        },
        ChatReply::text('Next question answered.'),
    ]);

    $this->postJson($this->url, ['text' => 'What do I need to buy?', 'turn_id' => 't-1'])
        ->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('audio_url', null);

    $conversation = AiConversation::sole();
    $results = $conversation->messages()->where('role', 'tool_results')->sole()->content['results'];
    expect($results[0]['content']['error'])->toBe('Not run: the person interrupted.')
        ->and($conversation->messages()->where('role', 'event')->sole()->text())->toBe(Assistant::INTERRUPTED)
        ->and($model->requests)->toHaveCount(1);

    $this->postJson("/api/households/{$this->home->id}/voice/turns/t-1/cancel")->assertStatus(202);
    $this->postJson("/api/households/{$this->home->id}/voice/turns/bad%20id/cancel")->assertNotFound();
});

test('"confirm" answers the change waiting in the conversation, hands-free; "no" declines it', function () {
    ScriptedChatModel::bind([
        ScriptedChatModel::calls(['add_shopping_item' => ['name' => 'Oat milk', 'category' => 'drinks']]),
        ChatReply::text('I can add oat milk.'),
        ScriptedChatModel::calls(['add_shopping_item' => ['name' => 'Eggs', 'category' => 'food']]),
        ChatReply::text('I can add eggs.'),
    ]);
    $first = $this->postJson($this->url, ['text' => 'Add oat milk', 'turn_id' => 't-1'])
        ->assertJsonPath('reply', "I can add oat milk.\n\nSay \"confirm\" or \"cancel\".")->json();
    $id = $first['conversation']['id'];

    $this->postJson($this->url, ['text' => 'Yes, please!', 'turn_id' => 't-2', 'conversation_id' => $id])
        ->assertOk()->assertJsonPath('reply', 'Done: Add "Oat milk" to the shopping list.')->assertJsonPath('actions.0.status', 'confirmed');
    expect(ShoppingItem::where('name', 'Oat milk')->exists())->toBeTrue();

    $this->postJson($this->url, ['text' => 'Add eggs', 'turn_id' => 't-3', 'conversation_id' => $id]);
    $this->postJson($this->url, ['text' => 'No', 'turn_id' => 't-4', 'conversation_id' => $id])
        ->assertJsonPath('reply', 'All right, I left it.')->assertJsonPath('actions.0.status', 'declined');
    expect(ShoppingItem::where('name', 'Eggs')->exists())->toBeFalse();
});

test('settings: voice, style and speed shape the answer; invalid choices are refused', function () {
    $this->patchJson('/api/voice', ['voice' => 'en_US-ryan-high', 'style' => 'butler', 'rate' => 1.25])->assertOk()
        ->assertJsonPath('settings.style', 'butler')->assertJsonPath('settings.voice', 'en_US-ryan-high');
    $this->patchJson('/api/voice', ['voice' => 'darth-vader', 'style' => 'shouty', 'rate' => 5, 'keep_days' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors(['voice', 'style', 'rate', 'keep_days']);

    $model = ScriptedChatModel::bind([ChatReply::text('Very good.')]);
    $this->postJson($this->url, ['text' => 'Good morning', 'turn_id' => 't-1'])->assertOk();

    expect($model->requests[0]->context)->toContain('courteous English butler')->toContain('address Maria by name')
        ->and(app(FakeVoice::class)->spoken[0])->toMatchArray(['voice' => 'en_US-ryan-high', 'rate' => 1.25]);

    $this->postJson('/api/voice/speak', ['text' => 'Testing, one two.', 'voice' => 'el_GR-rapunzelina-low'])->assertOk()
        ->assertJsonPath('audio_url', fn ($u) => str_contains($u, '/voice/audio/'));
});

test('the wake-word ticket: only with the service and the wake word on, signed like the service expects', function () {
    $this->getJson('/api/voice')->assertJsonPath('wake.url', null)->assertJsonPath('wake.available', false)
        ->assertJsonPath('settings.wake_word', false)->assertJsonCount(5, 'voices')->assertJsonPath('voices.0.label', 'Alan (British English)');

    config(['homehelper.voice.driver' => 'service']);
    VoiceSettings::for($this->me)->fill(['wake_word' => true])->save();
    $url = $this->getJson('/api/voice')->assertJsonPath('wake.phrase', 'Hey Jarvis')->json('wake.url');

    expect($url)->toStartWith('ws://localhost:8090/v1/wake?ticket='.$this->me->id.'.')
        // The same value services/voice/tests/test_api.py pins
        ->and(WakeTicket::issue(new User(['id' => 42])->forceFill(['id' => 42]), 1900000000))
        ->toBe('42.1900000000.95e44af0e58fc92f30b4a439b1f1cf732f6a4617217f0acbd03a3bc154cf6cfa');
});

test('privacy: delete my voice history; retention; and voice kept out of memory when asked', function () {
    ScriptedChatModel::bind([ChatReply::text('ok'), ChatReply::text('ok'), ChatReply::text('ok')]);
    $voice = $this->postJson($this->url, ['text' => 'Remember nothing', 'turn_id' => 't-1'])->json('conversation.id');
    $typed = app(Assistant::class)->start($this->home, $this->me);
    app(Assistant::class)->send($typed, $this->me, 'typed question');

    // Kept out of long-term memory
    VoiceSettings::for($this->me)->fill(['remember' => false])->save();
    AiConversation::query()->update(['updated_at' => now()->subHours(2)]);
    dispatch(new RememberConversations);
    expect(AiConversation::find($voice)->summary)->toBeNull()
        ->and(AiConversation::find($typed->id)->summary)->not->toBeNull()
        ->and(MemoryChunk::where('source_id', $voice)->exists())->toBeFalse();

    // Retention: older than 7 days goes
    VoiceSettings::for($this->me)->fill(['keep_days' => 7])->save();
    AiConversation::whereKey($voice)->update(['updated_at' => now()->subDays(8)]);
    AiConversation::whereKey($typed->id)->update(['updated_at' => now()->subDays(8)]);
    dispatch(new PruneVoiceConversations);
    expect(AiConversation::pluck('id')->all())->toBe([$typed->id]);

    // And all at once
    $this->postJson($this->url, ['text' => 'again', 'turn_id' => 't-2']);
    $this->deleteJson('/api/voice/history')->assertOk()->assertJsonPath('deleted_conversations', 1);
    expect(AiConversation::where('channel', 'voice')->count())->toBe(0)->and(AiConversation::count())->toBe(1);
});

test('someone else\'s conversation can\'t be continued by voice', function () {
    $alex = User::factory()->create();
    $this->home->addMember($alex);
    $theirs = app(Assistant::class)->start($this->home, $alex);

    $this->postJson($this->url, ['text' => 'hi', 'turn_id' => 't-1', 'conversation_id' => $theirs->id])->assertNotFound();
    $this->postJson($this->url, ['turn_id' => 't-1'])->assertUnprocessable()->assertJsonValidationErrors(['audio', 'text']);
});
