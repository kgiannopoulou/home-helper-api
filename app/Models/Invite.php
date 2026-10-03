<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email invitation to join a household.
 */
#[Fillable(['email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'invited_by'])]
#[Hidden(['token_hash'])]
class Invite extends Model
{
    /** @use HasFactory<InviteFactory> */
    use BelongsToHousehold, HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
