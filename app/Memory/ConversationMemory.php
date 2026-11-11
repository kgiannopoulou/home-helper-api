<?php

namespace App\Memory;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Support\Facades\Cache;

/**
 * Short-term memory: what of a conversation the model sees each turn
 * (docs/memory.md#conversation-summaries).
 *
 *   [summary of messages 1 … n]  +  messages n+1 … now, verbatim
 *
 * Phase 2 sent the whole conversation every turn. Now, once more than
 * `summarize_after` messages are unsummarised, the older part is folded into
 * the summary and only the newest `keep_recent` stay verbatim. The cut is
 * always just before one of the person's messages, so the verbatim part
 * starts a turn and every tool call keeps its result.
 *
 * The summary is also long-term memory: it is indexed (private to the
 * person), and conversations left idle are summarised whole, so a later
 * conversation can find "what we said about the boiler" (remember()).
 */
class ConversationMemory
{
    public function __construct(
        private Summarizer $summarizer,
        private MemoryIndexer $indexer,
    ) {}

    /**
     * The messages sent verbatim: everything after the summary.
     *
     * @return list<AiMessage>
     */
    public function window(AiConversation $conversation): array
    {
        return array_values($conversation->messages()
            ->when($conversation->summary_through_id, fn ($q, int $id) => $q->where('id', '>', $id))
            ->get()->all());
    }

    public function needsCompacting(AiConversation $conversation): bool
    {
        return $this->unsummarised($conversation) > (int) config('homehelper.memory.summarize_after');
    }

    /**
     * Folds all but the newest messages into the summary. Returns false when
     * there was nothing to fold (no turn boundary far enough back).
     */
    public function compact(AiConversation $conversation): bool
    {
        $messages = $this->window($conversation);
        $cut = count($messages) - (int) config('homehelper.memory.keep_recent');
        while ($cut > 0 && $cut < count($messages) && $messages[$cut]->role !== 'user') {
            $cut++;
        }
        if ($cut <= 0 || $cut >= count($messages)) {
            return false;
        }

        $this->fold($conversation, array_slice($messages, 0, $cut));

        return true;
    }

    /**
     * Summarises everything, for long-term memory, once the conversation has
     * gone quiet. The next message starts a fresh verbatim window after it.
     */
    public function remember(AiConversation $conversation): bool
    {
        $messages = $this->window($conversation);
        if (! array_filter($messages, fn (AiMessage $m) => $m->role === 'user')) {
            return false;
        }
        // Never end the summary inside a turn: the last message must be the assistant's answer or an event
        $last = end($messages);
        if ($last === false || $last->role === 'tool_results' || $last->role === 'user') {
            return false;
        }

        $this->fold($conversation, $messages);

        return true;
    }

    /** The key Assistant::send() holds while it answers; compaction waits for it */
    public static function lockKey(AiConversation $conversation): string
    {
        return "ai:conversation:{$conversation->id}";
    }

    /** @param list<AiMessage> $messages */
    private function fold(AiConversation $conversation, array $messages): void
    {
        $through = end($messages);
        $summary = $this->summarizer->summarize($conversation->summary, $messages);
        if ($summary === '' || $through === false) {
            return;
        }

        $conversation->forceFill([
            'summary' => mb_substr($summary, 0, 8000),
            'summary_through_id' => $through->id,
            'summarized_at' => now(),
        ]);
        // Summarising isn't activity: the conversation list keeps its order
        $conversation->timestamps = false;
        $conversation->save();
        $conversation->timestamps = true;

        $this->indexer->index($conversation);
    }

    private function unsummarised(AiConversation $conversation): int
    {
        return $conversation->messages()
            ->when($conversation->summary_through_id, fn ($q, int $id) => $q->where('id', '>', $id))
            ->count();
    }

    /** Runs $work while holding the conversation's lock, or returns false if a turn is in progress */
    public static function whileIdle(AiConversation $conversation, callable $work): bool
    {
        $lock = Cache::lock(self::lockKey($conversation), 300);
        if (! $lock->get()) {
            return false;
        }
        try {
            $work();
        } finally {
            $lock->release();
        }

        return true;
    }
}
