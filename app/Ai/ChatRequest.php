<?php

namespace App\Ai;

use App\Models\AiMessage;

/**
 * Everything one model turn needs.
 */
final readonly class ChatRequest
{
    /**
     * @param  string  $instructions  the same for every household and every turn (cacheable)
     * @param  string  $context  this household, this person, today (changes daily)
     * @param  list<AiMessage>  $transcript  the conversation so far, oldest first
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function __construct(
        public string $instructions,
        public string $context,
        public array $transcript,
        public array $tools,
    ) {}

    /** The newest thing the person typed (the offline model reads only this) */
    public function lastUserText(): string
    {
        foreach (array_reverse($this->transcript) as $message) {
            if ($message->role === 'user') {
                return $message->text();
            }
        }

        return '';
    }
}
