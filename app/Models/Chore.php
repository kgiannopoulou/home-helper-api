<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ChoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A repeating task in a room.
 */
#[Fillable(['room_id', 'assignee_id', 'name', 'every_days', 'minutes', 'learned_from_days', 'learned_on', 'fixed_frequency'])]
class Chore extends Model
{
    /** @use HasFactory<ChoreFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'every_days' => 'integer',
            'minutes' => 'integer',
            'learned_from_days' => 'integer',
            'learned_on' => 'date',
            'fixed_frequency' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<ChoreCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(ChoreCompletion::class);
    }

    /**
     * @return HasOne<ChoreCompletion, $this>
     */
    public function lastCompletion(): HasOne
    {
        return $this->hasOne(ChoreCompletion::class)->latestOfMany('done_at');
    }
}
