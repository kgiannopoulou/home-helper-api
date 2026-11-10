<?php

namespace App\Ai;

/**
 * A language model that can answer and call tools. The assistant only knows
 * this interface (docs/assistant.md); ClaudeChatModel is the real one,
 * OfflineChatModel answers without a model, and the tests script replies.
 *
 * One call is one model turn: the reply either ends the turn (text) or asks
 * for tool calls, which the Assistant runs and sends back in the next call.
 */
interface ChatModel
{
    /**
     * @throws AiUnavailable when the model can't be reached or isn't configured
     */
    public function reply(ChatRequest $request): ChatReply;

    /** "claude", "offline"… stored with each assistant message */
    public function name(): string;
}
