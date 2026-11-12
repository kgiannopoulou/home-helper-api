<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time pairing code (App\Agents\Enrollment). Only its HMAC is stored.
 *
 * @property string $id
 * @property string $household_id
 * @property int $user_id
 * @property string|null $name
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property string|null $agent_id
 * @property CarbonImmutable $created_at
 * @property-read Household $household
 * @property-read User $user
 */
class AgentEnrollmentCode extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'household_id'];

    protected $hidden = ['code_hash'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
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

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
