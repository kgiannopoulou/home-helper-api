<?php

use App\Ai\Assistant;
use App\Ai\ChatReply;
use App\Ai\ChatRequest;
use App\Enums\HouseholdRole;
use App\Jobs\RememberConversations;
use App\Memory\ConversationMemory;
use App\Memory\ExtractiveSummarizer;
use App\Memory\KnowledgeBase;
use App\Memory\ModelSummarizer;
use App\Memory\Summarizer;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Household;
use App\Models\MemoryChunk;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ScriptedChatModel;

/*
 * Day 30: conversation summaries. A long conversation is sent as
 * [summary] + the newest messages; a quiet one is summarised into long-term
 * memory, private to its person.
 */

beforeEach(function () {
    // Summaries without a model; the conversations themselves are scripted
    config(['homehelper.ai.provider' => 'offline', 'homehelper.memory.summarize_after' => 8, 'homehelper.memory.keep_recent' => 4]);
    $this->home = Household::factory()->create();
    $this->me = User::factory()->create(['name' => 'Maria']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
});

/** Turns that each look something up: user, assistant (tool call), tool_results, assistant (answer) */
function lookups(int $turns): array
{
    $script = [];
    for ($i = 1; $i <= $turns; $i++) {
        $script[] = ScriptedChatModel::calls(['get_shopping_list' => []]);
        $script[] = ChatReply::text("Answer {$i}. The list has milk.");
    }

    return $script;
}

test('a long conversation is compacted: the model gets the summary and only the newest messages, starting at a question', function () {
    $model = ScriptedChatModel::bind([...lookups(3), fn (ChatRequest $r) => ChatReply::text('Answer 4.')]);
    $assistant = app(Assistant::class);
    $conversation = $assistant->start($this->home, $this->me);

    foreach (['What do we need?', 'And bread?', 'Anything else?'] as $q) {
        $assistant->send($conversation, $this->me, $q);
    }
    // 12 messages > 8: everything before the last question that keeps ≥ 4 verbatim is summarised
    $conversation->refresh();
    expect($conversation->summary)->toContain('asked "What do we need?" → Answer 1.')
        ->and($conversation->summary)->toContain('asked "And bread?"')
        ->and($conversation->summary)->not->toContain('Anything else?')
        ->and($conversation->summarized_at)->not->toBeNull();

    $assistant->send($conversation, $this->me, 'Thanks!');
    $last = end($model->requests);
    expect($last->transcript[0]->role)->toBe('user')
        ->and($last->transcript[0]->text())->toBe('Anything else?')
        ->and(collect($last->transcript)->where('role', 'user')->map->text()->values()->all())->toBe(['Anything else?', 'Thanks!'])
        ->and($last->context)->toContain("Earlier in this conversation (summary):\n- ");

    // Summarising isn't activity, and it is in long-term memory, privately
    expect(MemoryChunk::where('source_type', 'conversation')->sole()->user_id)->toBe($this->me->id);
});

test('compaction never cuts inside a turn, and does nothing when no question is far enough back', function () {
    $conversation = app(Assistant::class)->start($this->home, $this->me);
    $conversation->messages()->create(['role' => 'user', 'content' => ['text' => 'Hi']]);
    foreach (range(1, 9) as $i) {
        $conversation->messages()->create(['role' => $i % 2 ? 'assistant' : 'tool_results', 'content' => $i % 2 ? ['blocks' => [['type' => 'text', 'text' => "a{$i}"]]] : ['results' => []]]);
    }

    expect(app(ConversationMemory::class)->needsCompacting($conversation))->toBeTrue()
        ->and(app(ConversationMemory::class)->compact($conversation))->toBeFalse()
        ->and($conversation->refresh()->summary)->toBeNull();
});

test('quiet conversations are remembered whole by the hourly job; busy or already-summarised ones are left', function () {
    ScriptedChatModel::bind([ChatReply::text('The plumber comes on Thursday at 10.'), ChatReply::text('Ok.')]);
    $assistant = app(Assistant::class);
    $quiet = $assistant->start($this->home, $this->me);
    $assistant->send($quiet, $this->me, 'When is the plumber coming?');
    $recent = $assistant->start($this->home, $this->me);
    $assistant->send($recent, $this->me, 'Hello');
    AiConversation::whereKey($quiet->id)->update(['updated_at' => now()->subHours(2)]);

    dispatch(new RememberConversations);

    expect($quiet->refresh()->summary)->toContain('When is the plumber coming?')->toContain('Thursday at 10')
        ->and($quiet->summary_through_id)->toBe($quiet->messages()->max('id'))
        ->and($recent->refresh()->summary)->toBeNull();

    // Found again later from a new conversation, by its person only
    $hits = app(KnowledgeBase::class)->search($this->home, $this->me, 'plumber coming Thursday');
    $alex = User::factory()->create();
    $this->home->addMember($alex);
    expect($hits[0]['source_type'])->toBe('conversation')
        ->and(app(KnowledgeBase::class)->search($this->home, $alex, 'plumber coming Thursday'))->toBe([]);

    // Nothing new since: not summarised again
    $at = $quiet->summarized_at;
    $this->travel(1)->minutes();
    dispatch(new RememberConversations);
    expect($quiet->refresh()->summarized_at->equalTo($at))->toBeTrue();
});

test('the summary waits for a turn in progress', function () {
    $conversation = app(Assistant::class)->start($this->home, $this->me);
    $lock = Cache::lock(ConversationMemory::lockKey($conversation), 60);
    $lock->get();

    expect(ConversationMemory::whileIdle($conversation, fn () => throw new RuntimeException('ran')))->toBeFalse();
    $lock->release();
});

test('the model summariser: one request without tools, the transcript as data, the old summary merged', function () {
    $model = new ScriptedChatModel([fn (ChatRequest $r) => ChatReply::text("- Decided to repaint the bathroom in May.\n- Budget €300.")]);
    $messages = [
        new AiMessage(['role' => 'user', 'content' => ['text' => 'Let\'s repaint the bathroom in May, €300 max.']]),
        new AiMessage(['role' => 'assistant', 'content' => ['blocks' => [['type' => 'text', 'text' => 'Noted.'], ['type' => 'tool_call', 'id' => 'x', 'name' => 'list_todos', 'input' => []]]]]),
        new AiMessage(['role' => 'tool_results', 'content' => ['results' => [['name' => 'list_todos', 'content' => ['secret' => 'not sent']]]]]),
        new AiMessage(['role' => 'event', 'content' => ['text' => 'The person confirmed "Add the to-do". It is done.']]),
    ];

    $summary = (new ModelSummarizer($model))->summarize('- Earlier: asked about paint colours.', $messages);
    $sent = $model->requests[0];

    expect($summary)->toBe("- Decided to repaint the bathroom in May.\n- Budget €300.")
        ->and($sent->tools)->toBe([])
        ->and($sent->instructions)->toBe(ModelSummarizer::INSTRUCTIONS)
        ->and($sent->transcript[0]->text())->toContain("Summary so far:\n- Earlier: asked about paint colours.")
        ->toContain('Person (')->toContain('Home Helper: Noted.')->toContain('[The person confirmed "Add the to-do". It is done.]')
        ->not->toContain('not sent');
});

test('offline, the extractive summariser keeps questions, first sentences and confirmations, newest last', function () {
    $s = (new ExtractiveSummarizer)->summarize(null, [
        new AiMessage(['role' => 'user', 'content' => ['text' => "What's   the rent?"]]),
        new AiMessage(['role' => 'assistant', 'content' => ['blocks' => [['type' => 'text', 'text' => 'Rent is €620, due on the 1st. It goes up in January.']]]]),
        new AiMessage(['role' => 'event', 'content' => ['text' => 'The person confirmed "Add milk". It is done.']]),
    ]);

    expect($s)->toBe("- asked \"What's the rent?\" → Rent is €620, due on the 1st.\n- The person confirmed \"Add milk\". It is done.")
        ->and(app(Summarizer::class))->toBeInstanceOf(ExtractiveSummarizer::class);

    config(['homehelper.ai.provider' => 'claude']);
    expect(app(Summarizer::class))->toBeInstanceOf(ModelSummarizer::class);
});
