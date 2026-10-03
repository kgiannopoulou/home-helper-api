<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\SleepEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One night of sleep; hours is worked out by MySQL.
 */
#[Fillable(['user_id', 'date', 'bed_at', 'woke_at', 'quality'])]
class SleepEntry extends Model
{
    /** @use HasFactory<SleepEntryFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'bed_at' => 'datetime',
            'woke_at' => 'datetime',
            'hours' => 'decimal:2',
            'quality' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
