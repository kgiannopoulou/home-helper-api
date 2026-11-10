<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a conversation's transcript. `content` by role:
 *
 *   user          {"text": "What do I need to buy?"}
 *   assistant     {"blocks": [{"type": "text", "text": …} | {"type": "tool_call", "id", "name", "input"}],
 *                  "provider": "claude", "raw": [the provider's own content blocks]}
 *   tool_results  {"results": [{"tool_call_id", "name", "content": {…}, "is_error": bool}]}
 *   event         {"text": "The person confirmed: …"}  something that happened between turns
 *
 * `raw` is sent back to the same provider unchanged, so thinking blocks and
 * their signatures stay exactly as the model wrote them.
 *
 * @property int $id
 * @property string $conversation_id
 * @property string $role
 * @property array<string, mixed> $content
 * @property string|null $model
 * @property string|null $stop_reason
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $cache_read_tokens
 * @property int|null $cache_write_tokens
 * @property CarbonImmutable $created_at
 */
class AiMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** The text the assistant said, its text blocks joined */
    public function text(): string
    {
        return match ($this->role) {
            'user', 'event' => (string) ($this->content['text'] ?? ''),
            'assistant' => trim(implode("\n\n", array_map(
                fn (array $b) => $b['text'],
                array_filter($this->content['blocks'] ?? [], fn (array $b) => $b['type'] === 'text'),
            ))),
            default => '',
        };
    }

    /** @return list<array{id: string, name: string, input: array<string, mixed>}> */
    public function toolCalls(): array
    {
        return array_values(array_map(
            fn (array $b) => ['id' => $b['id'], 'name' => $b['name'], 'input' => (array) $b['input']],
            array_filter($this->content['blocks'] ?? [], fn (array $b) => $b['type'] === 'tool_call'),
        ));
    }
}
