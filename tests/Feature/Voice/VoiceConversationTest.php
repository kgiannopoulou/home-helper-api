<?php

use App\Models\AiConversation;
use App\Models\ShoppingItem;
use App\Models\User;
use App\Models\VoiceSettings;
use App\Voice\FakeVoice;
use App\Weather\WeatherProvider;
use App\Web\CurrentHousehold;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FakeWeatherProvider;

/*
 * Day 56: the whole voice experience, as the dashboard drives it, on the demo
 * household with the offline model doing the routing for real and the fake
 * speech engines: a question, a follow-up in the same conversation, a change
 * proposed and confirmed by saying "yes", and an interruption. Then the
 * settings page and the assistant page's voice configuration.
 */

beforeEach(function () {
    app()->instance(WeatherProvider::class, new FakeWeatherProvider);
    config(['homehelper.ai.provider' => 'offline', 'homehelper.voice.driver' => 'fake', 'homehelper.voice.token' => 'secret']);
    $this->seed(DemoSeeder::class);
    $this->owner = User::where('email', 'demo@homehelper.test')->sole();
    $this->home = $this->owner->households()->sole();
    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
});

function talk(string $words, ?string $conversation = null, string $turn = 't'): array
{
    return test()->post('/voice/turns', array_filter([
        'audio' => UploadedFile::fake()->createWithContent('speech.webm', "TEXT:{$words}"),
        'turn_id' => $turn.'-'.uniqid(),
        'conversation_id' => $conversation,
    ]), ['Accept' => 'application/json'])->assertOk()->json();
}

test('a spoken conversation: ask, follow up, change something by saying yes', function () {
    $first = talk('What do I need to buy?');
    $id = $first['conversation']['id'];

    expect($first['heard'])->toBe('What do I need to buy?')
        ->and($first['reply'])->toStartWith('To buy:')
        // Read out as a sentence, not a list
        ->and($first['spoken'])->toStartWith('To buy: ')->not->toContain("\n- ")
        ->and($first['audio_url'])->toContain('/voice/audio/');
    $this->get($first['audio_url'])->assertOk()->assertHeader('Content-Type', 'audio/wav');

    $second = talk('When did we last descale the kettle?', $id);
    expect($second['conversation']['id'])->toBe($id)
        ->and($second['reply'])->toContain('Descale the kettle');

    $third = talk('Add oat milk to the shopping list', $id);
    expect($third['actions'][0]['status'])->toBe('pending')->and($third['reply'])->toEndWith('Say "confirm" or "cancel".');

    $yes = talk('Yes please', $id);
    expect($yes['reply'])->toBe('Done: Add "Oat milk" to the shopping list.')
        ->and(ShoppingItem::where('name', 'Oat milk')->exists())->toBeTrue()
        ->and(AiConversation::where('channel', 'voice')->count())->toBe(1);

    // Every answer went to text to speech in the default voice
    expect(collect(app(FakeVoice::class)->spoken)->pluck('voice')->unique()->all())->toBe(['en_GB-alan-medium']);
});

test('the dashboard can stop a turn it started, and the page knows how to talk', function () {
    $this->postJson('/voice/turns/abc-123/cancel')->assertStatus(202)->assertJsonPath('cancelling', true);

    $this->get('/assistant')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('voice.driver', 'fake')
        ->where('voice.settings.style', 'conversational')
        ->where('voice.wake.phrase', 'Hey Jarvis')
        ->has('voice.voices', 5));
});

test('Settings → Voice: the page, a change, and deleting voice history', function () {
    talk('What do I need to buy?');

    $this->get('/settings/voice')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('settings/voice')
        ->where('voice.settings.wake_word', false)
        ->has('voice.styles', 4));

    $this->patchJson('/settings/voice', ['style' => 'butler', 'keep_days' => 7, 'remember' => false])->assertOk()
        ->assertJsonPath('settings.keep_days', 7);
    expect(VoiceSettings::where('user_id', $this->owner->id)->sole()->only(['keep_days', 'remember']))->toBe(['keep_days' => 7, 'remember' => false]);

    $this->deleteJson('/settings/voice/history')->assertOk()->assertJsonPath('deleted_conversations', 1);
});
