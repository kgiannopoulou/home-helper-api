<?php

namespace App\Models;

use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Kitchen\Expiry;
use App\Kitchen\Ingredients;
use App\Kitchen\Units;
use App\Models\Concerns\BelongsToHousehold;
use App\Support\HomeTime;
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
 * Two ways to say how much (docs/kitchen.md#amounts-and-units): the phone's
 * level (full, half, low, empty) and free-text quantity, and since Phase 7 a
 * measured amount in a base unit. When there's an amount, the level follows
 * it: amount / full_amount.
 *
 * @property string $id
 * @property string $household_id
 * @property string $name
 * @property string|null $ingredient App\Kitchen\Ingredients::key() of the name, unless set
 * @property ItemCategory $category
 * @property StorageLocation|null $location
 * @property StockLevel|null $level
 * @property string|null $quantity
 * @property float|null $amount in $unit
 * @property string|null $unit g|ml|pcs
 * @property float|null $full_amount the most it has held, in $unit
 * @property string|null $barcode the pack it was scanned as (App\Kitchen\Barcodes\Gtin)
 * @property float|null $pack_amount what one scanned pack adds, in $unit
 * @property CarbonImmutable|null $expires_on use by; estimated (App\Kitchen\ShelfLife) unless typed
 * @property bool $expiry_estimated
 * @property CarbonImmutable|null $opened_on
 * @property CarbonImmutable|null $expiry_notified_on the date the morning push already mentioned
 */
#[Fillable(['name', 'ingredient', 'category', 'location', 'level', 'quantity', 'amount', 'unit', 'full_amount', 'expires_on'])]
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
            'amount' => 'float',
            'full_amount' => 'float',
            'pack_amount' => 'float',
            'expires_on' => 'date',
            'expiry_estimated' => 'boolean',
            'opened_on' => 'date',
            'expiry_notified_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InventoryItem $item) {
            // Into the freezer or out of it: a new use-by date (day 88)
            if ($item->exists && $item->isDirty('location')) {
                app(Expiry::class)->moved($item, StorageLocation::tryFrom((string) $item->getRawOriginal('location')), HomeTime::today());
            }
            // The key follows the name, unless someone chose it
            if (($item->isDirty('name') && ! $item->isDirty('ingredient')) || ! $item->ingredient) {
                $item->ingredient = Ingredients::key($item->name);
            }
            // The phone sends "500 g"; read an amount from it when none was given
            if ($item->isDirty('quantity') && ! $item->isDirty('amount') && ($parsed = Units::parse($item->quantity))) {
                [$item->amount, $item->unit] = $parsed;
            }
            if ($item->isDirty('amount') && $item->amount !== null) {
                $item->amount = max(0.0, round($item->amount, 3));
                if ($item->amount > 0 && ($item->full_amount === null || $item->amount > $item->full_amount)) {
                    $item->full_amount = $item->amount;
                }
                // A level sent together with the amount (the phone's own) wins
                if (! $item->isDirty('level')) {
                    $item->level = self::levelFor($item->amount, $item->full_amount);
                }
            }
        });
    }

    /** The phone's four levels from an amount */
    public static function levelFor(float $amount, ?float $full): StockLevel
    {
        if ($amount <= 0) {
            return StockLevel::Empty;
        }
        $ratio = $full ? $amount / $full : 1.0;

        return match (true) {
            $ratio > 0.6 => StockLevel::Full,
            $ratio > 0.3 => StockLevel::Half,
            default => StockLevel::Low,
        };
    }

    /** "750 ml", or the free text, or "" */
    public function amountText(): string
    {
        return $this->amount !== null ? Units::format($this->amount, $this->unit) : (string) $this->quantity;
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

    /**
     * @return HasMany<KitchenMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(KitchenMovement::class)->orderByDesc('occurred_at');
    }
}
