<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One time an inventory item was bought.
 */
#[Fillable(['inventory_item_id', 'shopping_trip_id', 'bought_on', 'price'])]
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'bought_on' => 'date',
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * @return BelongsTo<ShoppingTrip, $this>
     */
    public function shoppingTrip(): BelongsTo
    {
        return $this->belongsTo(ShoppingTrip::class);
    }
}
