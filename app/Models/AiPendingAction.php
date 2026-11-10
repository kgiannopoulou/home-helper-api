<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change the assistant proposed ("add milk to the shopping list") that only
 * happens when the person presses Confirm. Expires after
 * homehelper.ai.confirmation_minutes.
 *
 * @property string $id
 * @property string $conversation_id
 * @property int $user_id
 * @property string $tool
 * @property array<string, mixed> $input
 * @property string $summary
 * @property string $status pending|confirmed|declined|expired|failed
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 */
class AiPendingAction extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
}
