<?php

namespace App\Models;

use App\Enums\TripSource;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ShoppingTripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One shop: when, where and how much.
 */
#[Fillable(['user_id', 'date', 'store', 'total', 'item_count', 'source'])]
class ShoppingTrip extends Model
{
    /** @use HasFactory<ShoppingTripFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total' => 'decimal:2',
            'item_count' => 'integer',
            'source' => TripSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * @return HasMany<ItemPrice, $this>
     */
    public function itemPrices(): HasMany
    {
        return $this->hasMany(ItemPrice::class);
    }
}
