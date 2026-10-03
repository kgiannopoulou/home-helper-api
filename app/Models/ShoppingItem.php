<?php

namespace App\Models;

use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\ShoppingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An item on the shared shopping list.
 */
#[Fillable(['added_by', 'name', 'category', 'quantity', 'price', 'checked', 'source'])]
class ShoppingItem extends Model
{
    /** @use HasFactory<ShoppingItemFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => ItemCategory::class,
            'price' => 'decimal:2',
            'checked' => 'boolean',
            'source' => ShoppingSource::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
