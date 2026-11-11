<?php

namespace App\Memory;

use App\Ai\AiUnavailable;
use App\Models\AiMessage;

/**
 * Writes the summary that replaces the older part of a conversation
 * (docs/memory.md#conversation-summaries).
 */
interface Summarizer
{
    /**
     * @param  string|null  $previous  the summary so far, which the new one replaces
     * @param  list<AiMessage>  $messages  the messages since, oldest first
     *
     * @throws AiUnavailable
     */
    public function summarize(?string $previous, array $messages): string;
}
