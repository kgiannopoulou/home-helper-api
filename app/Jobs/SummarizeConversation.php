<?php

namespace App\Jobs;

use App\Ai\AiUnavailable;
use App\Memory\ConversationMemory;
use App\Models\AiConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compacts a long conversation (after a turn), or summarises a quiet one
 * into long-term memory ($whole, from RememberConversations). Waits for the
 * conversation's lock, so it never rewrites the summary in the middle of a turn.
 */
class SummarizeConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public AiConversation $conversation, public bool $whole = false) {}

    public function handle(ConversationMemory $memory): void
    {
        try {
            $ran = ConversationMemory::whileIdle($this->conversation, fn () => $this->whole
                ? $memory->remember($this->conversation)
                : ($memory->needsCompacting($this->conversation) && $memory->compact($this->conversation)));
        } catch (AiUnavailable $e) {
            // The model is down: the conversation still works verbatim, try again later
            $this->release(300);

            return;
        }

        if (! $ran) {
            $this->release(30); // a turn is in progress
        }
    }
}
