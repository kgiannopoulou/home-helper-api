<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ItemPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A price seen for an item on a day.
 */
#[Fillable(['shopping_trip_id', 'name', 'price', 'seen_on'])]
class ItemPrice extends Model
{
    /** @use HasFactory<ItemPriceFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'seen_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ShoppingTrip, $this>
     */
    public function shoppingTrip(): BelongsTo
    {
        return $this->belongsTo(ShoppingTrip::class);
    }
}
