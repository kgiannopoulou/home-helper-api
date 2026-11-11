<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat with the assistant. Private to the person who started it, and
 * always about one household (docs/assistant.md).
 *
 * @property string $id
 * @property string $household_id
 * @property int $user_id
 * @property string|null $title
 * @property string $channel text | voice (docs/voice.md#privacy)
 * @property string|null $summary the messages up to summary_through_id, summarised (docs/memory.md#conversation-summaries)
 * @property int|null $summary_through_id
 * @property CarbonImmutable|null $summarized_at
 * @property CarbonImmutable $updated_at
 * @property-read Household $household
 */
#[Fillable(['title', 'summary', 'summary_through_id', 'summarized_at'])]
class AiConversation extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['summarized_at' => 'datetime'];
    }

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AiMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('id');
    }

    /** @return HasMany<AiPendingAction, $this> */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(AiPendingAction::class, 'conversation_id');
    }
}
