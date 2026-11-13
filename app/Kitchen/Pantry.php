<?php

namespace App\Kitchen;

use App\Models\InventoryItem;
use App\Models\KitchenMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change of an amount in the kitchen goes through here
 * (docs/kitchen.md#movements): one kitchen_movements row, the item's amount
 * and level updated in the same transaction, the row locked so two phones
 * using the last eggs at once can't both succeed.
 *
 * An item without a measured amount (only the phone's level) still records
 * the movement; its amount starts from what came in.
 */
class Pantry
{
    /**
     * Something came in: a shop, a receipt, a scanned pack
     *
     * @param  float  $amount  in $unit (any unit Units knows)
     */
    public function add(InventoryItem $item, float $amount, string $unit, ?User $user, string $note = '', ?CarbonImmutable $at = null): KitchenMovement
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Add more than nothing.']);
        }

        return $this->move($item, 'bought', $amount, $unit, $user, $note, $at);
    }

    /**
     * Something left: eaten, cooked, thrown away. Taking more than there is
     * takes what there is (the kitchen is right, the number was wrong).
     *
     * @param  string  $kind  used|cooked|wasted|expired
     */
    public function take(InventoryItem $item, string $kind, ?float $amount, ?string $unit, ?User $user, string $note = '', ?CarbonImmutable $at = null): KitchenMovement
    {
        if (! in_array($kind, ['used', 'cooked', 'wasted', 'expired'], true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown kind of use.']);
        }
        if ($amount !== null && $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Use more than nothing.']);
        }

        return $this->move($item, $kind, $amount === null ? null : -$amount, $unit, $user, $note, $at);
    }

    /** "We actually have 3 left": the difference is the movement */
    public function set(InventoryItem $item, float $amount, string $unit, ?User $user, string $note = ''): KitchenMovement
    {
        return DB::transaction(function () use ($item, $amount, $unit, $user, $note) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            [$value, $base] = $this->inItemUnit($locked, ...$this->base($amount, $unit));
            $current = $this->current($locked, $base);

            return $this->record($locked, 'adjusted', $value - $current, $base, $user, $note, null);
        });
    }

    private function move(InventoryItem $item, string $kind, ?float $signed, ?string $unit, ?User $user, string $note, ?CarbonImmutable $at): KitchenMovement
    {
        return DB::transaction(function () use ($item, $kind, $signed, $unit, $user, $note, $at) {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($signed === null) {
                // "Used it up" / "threw it away": all of it
                if ($locked->amount === null) {
                    throw ValidationException::withMessages(['amount' => "How much? {$locked->name} has no amount yet."]);
                }
                [$value, $base] = [-$locked->amount, (string) $locked->unit];
            } else {
                [$value, $base] = $this->inItemUnit($locked, ...$this->base($signed, $unit ?? $locked->unit ?? 'pcs'));
            }
            $current = $this->current($locked, $base);
            // Never below zero: record what really left
            if ($current + $value < 0) {
                $value = -$current;
            }

            $row = $this->record($locked, $kind, $value, $base, $user, $note, $at);
            $item->setRawAttributes($locked->getAttributes(), true);

            return $row;
        });
    }

    private function record(InventoryItem $locked, string $kind, float $value, string $base, ?User $user, string $note, ?CarbonImmutable $at): KitchenMovement
    {
        $movement = new KitchenMovement([
            'inventory_item_id' => $locked->id,
            'user_id' => $user?->id,
            'kind' => $kind,
            'amount' => round($value, 3),
            'unit' => $base,
            'note' => $note !== '' ? mb_substr($note, 0, 200) : null,
            'occurred_at' => $at ?? CarbonImmutable::now(),
        ]);
        $movement->household_id = $locked->household_id;
        $movement->save();

        $locked->forceFill([
            'unit' => $base,
            'amount' => max(0.0, $this->current($locked, $base) + $value),
        ])->save();

        return $movement;
    }

    /** What the item has now, in $base; 0 when it has no amount yet */
    private function current(InventoryItem $item, string $base): float
    {
        if ($item->amount === null || $item->unit === null) {
            return 0.0;
        }
        $converted = Units::convert($item->amount, $item->unit, $base);
        if ($converted === null) {
            throw ValidationException::withMessages(['unit' => "{$item->name} is counted in ".($item->unit === 'pcs' ? 'pieces' : $item->unit).', not '.($base === 'pcs' ? 'pieces' : $base).'.']);
        }

        return $converted;
    }

    /**
     * Grams for an item counted in millilitres become millilitres: the item keeps its unit
     *
     * @return array{0: float, 1: string}
     */
    private function inItemUnit(InventoryItem $item, float $value, string $base): array
    {
        if ($item->amount === null || $item->unit === null) {
            return [$value, $base];
        }
        $this->current($item, $base);

        return [(float) Units::convert($value, $base, $item->unit), $item->unit];
    }

    /** @return array{0: float, 1: string} */
    private function base(float $amount, string $unit): array
    {
        $b = Units::toBase($amount, $unit);
        if ($b === null || ! in_array($b[1], Units::BASE, true)) {
            throw ValidationException::withMessages(['unit' => "Unknown unit {$unit}."]);
        }

        return $b;
    }
}
