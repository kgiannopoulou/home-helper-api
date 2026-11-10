<?php

namespace App\Ai;

/**
 * One model turn's answer, in the shape the assistant stores.
 */
final readonly class ChatReply
{
    /**
     * @param  list<array{type: 'text', text: string}|array{type: 'tool_call', id: string, name: string, input: array<string, mixed>}>  $blocks
     * @param  list<array<string, mixed>>  $raw  the provider's own content blocks, replayed unchanged
     * @param  string  $stopReason  end_turn | tool_use | max_tokens | refusal | …
     * @param  array{input?: int, output?: int, cache_read?: int, cache_write?: int}  $usage
     */
    public function __construct(
        public array $blocks,
        public string $stopReason,
        public string $model,
        public array $raw = [],
        public array $usage = [],
    ) {}

    /** @return list<array{type: 'tool_call', id: string, name: string, input: array<string, mixed>}> */
    public function toolCalls(): array
    {
        return array_values(array_filter($this->blocks, fn (array $b) => $b['type'] === 'tool_call'));
    }

    public static function text(string $text, string $model = 'offline'): self
    {
        return new self([['type' => 'text', 'text' => $text]], 'end_turn', $model);
    }
}
