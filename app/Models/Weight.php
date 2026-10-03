<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\WeightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person's weight on a day.
 */
#[Fillable(['user_id', 'date', 'kg'])]
class Weight extends Model
{
    /** @use HasFactory<WeightFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kg' => 'decimal:2',
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
