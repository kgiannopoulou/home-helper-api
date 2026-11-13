<?php

namespace App\Models;

use App\Kitchen\Units;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of an inventory item's amount (App\Kitchen\Pantry). Signed:
 * bought is positive, used, cooked, wasted and expired are negative.
 *
 * @property string $id
 * @property string $household_id
 * @property string $inventory_item_id
 * @property int|null $user_id
 * @property string $kind bought|used|cooked|wasted|expired|adjusted
 * @property float $amount in $unit, signed
 * @property string $unit g|ml|pcs
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property-read InventoryItem $item
 * @property-read User|null $user
 */
class KitchenMovement extends Model
{
    use BelongsToHousehold, HasUlids;

    public const KINDS = ['bought', 'used', 'cooked', 'wasted', 'expired', 'adjusted'];

    /** What leaves the kitchen without being eaten */
    public const WASTE = ['wasted', 'expired'];

    protected $guarded = ['id', 'household_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "−250 ml", "+1 kg" */
    public function label(): string
    {
        return ($this->amount >= 0 ? '+' : '−').Units::format(abs($this->amount), $this->unit);
    }
}
