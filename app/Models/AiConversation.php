<?php

namespace App\Models;

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
 */
#[Fillable(['title'])]
class AiConversation extends Model
{
    use HasUlids;

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
