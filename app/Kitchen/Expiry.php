<?php

namespace App\Kitchen;

use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Models\Household;
use App\Models\InventoryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Use-by dates (docs/kitchen.md#expiry). A date typed from the pack is the
 * truth; without one Home Helper estimates (ShelfLife) and says so:
 *
 *   bought    a new pack: today + how long it keeps where it's kept, unless
 *             a typed date for an earlier pack is still ahead (the older
 *             pack goes off first)
 *   opened    the date can only come closer: today + days once opened
 *   frozen    moved to the freezer: today + days frozen
 *   thawed    out of the freezer: two days at most
 *
 * Only food that is actually there counts: an empty item has no date to
 * worry about.
 */
class Expiry
{
    /** Days ahead that count as "use soon" */
    public const SOON_DAYS = 2;

    /**
     * @return array{state: 'expired'|'today'|'soon'|'week'|'ok'|'none', days: int|null}
     */
    public static function status(InventoryItem $item, CarbonImmutable $today): array
    {
        if (! $item->expires_on || ! self::inStock($item)) {
            return ['state' => 'none', 'days' => null];
        }
        $days = (int) $today->startOfDay()->diffInDays(CarbonImmutable::parse($item->expires_on->toDateString(), $today->timezone), false);

        return ['state' => match (true) {
            $days < 0 => 'expired',
            $days === 0 => 'today',
            $days <= self::SOON_DAYS => 'soon',
            $days <= 7 => 'week',
            default => 'ok',
        }, 'days' => $days];
    }

    public static function inStock(InventoryItem $item): bool
    {
        return $item->amount !== null ? $item->amount > 0 : $item->level !== StockLevel::Empty;
    }

    /**
     * What's in stock and goes off within $days (or already has), soonest first
     *
     * @return Collection<int, InventoryItem>
     */
    public function expiring(Household $household, CarbonImmutable $today, int $days = 7): Collection
    {
        return $household->inventoryItems()->whereNotNull('expires_on')
            ->where('expires_on', '<=', $today->addDays($days)->toDateString())
            ->orderBy('expires_on')->orderBy('name')->get()
            ->filter(fn (InventoryItem $i) => self::inStock($i))->values();
    }

    /** A new pack came in */
    public function bought(InventoryItem $item, CarbonImmutable $today, bool $wasEmpty): void
    {
        $typedAndAhead = $item->expires_on && ! $item->expiry_estimated && $item->expires_on->toDateString() >= $today->toDateString();
        if ($typedAndAhead && ! $wasEmpty) {
            return;
        }
        $item->forceFill([
            'expires_on' => $today->addDays(ShelfLife::days((string) $item->ingredient, $item->location)),
            'expiry_estimated' => true,
            'opened_on' => null,
            'expiry_notified_on' => null,
        ]);
    }

    public function opened(InventoryItem $item, CarbonImmutable $today): void
    {
        $item->opened_on = $today;
        $days = ShelfLife::openedDays((string) $item->ingredient);
        if ($days === null || $item->location === StorageLocation::Freezer) {
            return;
        }
        $date = $today->addDays($days);
        if (! $item->expires_on || $date->toDateString() < $item->expires_on->toDateString()) {
            $item->forceFill(['expires_on' => $date, 'expiry_estimated' => true, 'expiry_notified_on' => null]);
        }
    }

    /** It moved: into the freezer it keeps for months, out of it for two days at most */
    public function moved(InventoryItem $item, ?StorageLocation $from, CarbonImmutable $today): void
    {
        if (! self::inStock($item) || $from === $item->location) {
            return;
        }
        if ($item->location === StorageLocation::Freezer) {
            $item->forceFill(['expires_on' => $today->addDays(ShelfLife::days((string) $item->ingredient, StorageLocation::Freezer)), 'expiry_estimated' => true, 'expiry_notified_on' => null]);
        } elseif ($from === StorageLocation::Freezer) {
            $item->forceFill(['expires_on' => $today->addDays(min(2, ShelfLife::days((string) $item->ingredient, $item->location))), 'expiry_estimated' => true, 'expiry_notified_on' => null]);
        }
    }

    /** The date printed on the pack */
    public function set(InventoryItem $item, CarbonImmutable $date): void
    {
        $item->forceFill(['expires_on' => $date, 'expiry_estimated' => false, 'expiry_notified_on' => null])->save();
    }

    /** "Still good": looked at it, smelt it; ask again in two days */
    public function stillGood(InventoryItem $item, CarbonImmutable $today): void
    {
        $item->forceFill(['expires_on' => $today->addDays(self::SOON_DAYS), 'expiry_estimated' => true, 'expiry_notified_on' => null])->save();
    }
}
