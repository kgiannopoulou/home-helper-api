<?php

namespace App\Models;

use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something in the fridge, freezer or cupboards.
 *
 * @property string $id
 * @property string $household_id
 * @property string $name
 * @property ItemCategory $category
 * @property StorageLocation|null $location
 * @property StockLevel|null $level
 * @property string|null $quantity
 * @property CarbonImmutable|null $expires_on
 */
#[Fillable(['name', 'category', 'location', 'level', 'quantity', 'expires_on'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'category' => ItemCategory::class,
            'location' => StorageLocation::class,
            'level' => StockLevel::class,
            'expires_on' => 'date',
        ];
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class)->orderBy('bought_on');
    }

    /**
     * @return HasOne<Purchase, $this>
     */
    public function latestPurchase(): HasOne
    {
        return $this->hasOne(Purchase::class)->latestOfMany('bought_on');
    }
}
