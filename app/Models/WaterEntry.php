<?php

namespace App\Models;

use App\Enums\Drink;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\WaterEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A drink one person had.
 */
#[Fillable(['user_id', 'date', 'drunk_at', 'ml', 'drink'])]
class WaterEntry extends Model
{
    /** @use HasFactory<WaterEntryFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'drunk_at' => 'datetime',
            'ml' => 'integer',
            'drink' => Drink::class,
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
