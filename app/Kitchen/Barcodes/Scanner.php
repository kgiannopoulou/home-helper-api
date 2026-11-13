<?php

namespace App\Kitchen\Barcodes;

use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Kitchen\Ingredients;
use App\Kitchen\Pantry;
use App\Kitchen\Units;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A scanned barcode, start to finish (docs/kitchen.md#barcodes):
 *
 *   1. check the digits (Gtin): a misread never reaches anything else
 *   2. the household's own item with this barcode, if it has one
 *   3. the product: the products cache, else Open Food Facts (cached 30 days
 *      when found, 7 when not; shop-internal codes are never looked up)
 *   4. no item yet, but the household has the same ingredient: suggest it
 *      ("You have Milk. Is this it?") instead of a second milk
 *
 * Then apply(): add a pack or use one, creating the item the first time and
 * remembering the barcode on it, so next time it's one tap even when Open
 * Food Facts doesn't know the product.
 */
class Scanner
{
    public function __construct(private ProductLookup $lookup, private Pantry $pantry) {}

    /**
     * @return array{gtin: string, item: InventoryItem|null, suggestion: InventoryItem|null, product: ProductInfo|null, lookup_error: string|null}
     */
    public function scan(Household $household, string $code): array
    {
        $gtin = self::gtin($code);
        $item = $household->inventoryItems()->where('barcode', $gtin)->first();
        $error = null;
        try {
            $product = $this->product($gtin);
        } catch (LookupFailed $e) {
            $product = null;
            $error = $e->getMessage();
        }

        $suggestion = null;
        if (! $item && $product) {
            $suggestion = $household->inventoryItems()->where('ingredient', $product->ingredient)->whereNull('barcode')->orderBy('name')->first();
        }

        return ['gtin' => $gtin, 'item' => $item, 'suggestion' => $suggestion, 'product' => $product, 'lookup_error' => $error];
    }

    /**
     * Add or use one pack (or $amountText of it)
     *
     * @param  array{item_id?: string|null, name?: string|null, location?: string|null, amount_text?: string|null}  $input
     */
    public function apply(Household $household, User $user, string $code, string $action, array $input = []): InventoryItem
    {
        $scan = $this->scan($household, $code);
        $product = $scan['product'];

        return DB::transaction(function () use ($household, $user, $scan, $product, $action, $input) {
            $item = $scan['item'];
            if (! $item && ! empty($input['item_id'])) {
                // "Yes, this is our milk": the barcode joins the existing item
                $item = $household->inventoryItems()->findOrFail($input['item_id']);
            }
            if (! $item) {
                if ($action === 'use') {
                    throw ValidationException::withMessages(['code' => 'This isn\'t in the kitchen yet: add it first.']);
                }
                $name = trim((string) ($input['name'] ?? '')) ?: $product?->name;
                if (! $name) {
                    throw ValidationException::withMessages(['name' => 'Nobody knows this barcode yet. What is it?']);
                }
                $item = $household->inventoryItems()->where('name', $name)->first() ?? $household->inventoryItems()->create([
                    'name' => mb_substr($name, 0, 100),
                    'ingredient' => $product->ingredient ?? Ingredients::key($name),
                    'category' => $product->category ?? ItemCategory::Food,
                    'location' => $input['location'] ?? $product?->location->value ?? StorageLocation::Pantry->value,
                ]);
            }
            [$amount, $unit] = $this->amount($input['amount_text'] ?? null, $product, $item);
            // The barcode and the pack's size stay on the item: next scan is one tap
            $item->forceFill(['barcode' => $scan['gtin'], 'pack_amount' => $action === 'add' ? $amount : ($item->pack_amount ?? $amount)]);
            if ($item->isDirty()) {
                $item->save();
            }
            $note = 'scanned '.$scan['gtin'];
            if ($action === 'use') {
                $this->pantry->take($item, 'used', $amount, $unit, $user, $note);
            } else {
                $this->pantry->add($item, $amount, $unit, $user, $note);
            }

            return $item->fresh() ?? $item;
        });
    }

    /** The cached product, or ask the lookup and cache the answer */
    public function product(string $gtin): ?ProductInfo
    {
        $cached = Product::query()->find($gtin);
        $days = $cached?->found ? config('homehelper.kitchen.barcodes.found_days') : config('homehelper.kitchen.barcodes.missing_days');
        if ($cached && $cached->fetched_at->gt(CarbonImmutable::now()->subDays((int) $days))) {
            return $cached->info();
        }
        if (Gtin::isInStore($gtin)) {
            return null;
        }

        $info = $this->lookup->find($gtin);
        Product::remember($gtin, $info);
        Log::info('Barcode looked up', ['gtin' => $gtin, 'found' => $info !== null]);

        return $info;
    }

    /** @throws ValidationException */
    public static function gtin(string $code): string
    {
        return Gtin::normalize($code) ?? throw ValidationException::withMessages(['code' => 'That isn\'t a product barcode (check the digits).']);
    }

    /**
     * What one scan adds or uses: what was typed, else the pack's size, else one piece
     *
     * @return array{0: float, 1: string}
     */
    private function amount(?string $text, ?ProductInfo $product, InventoryItem $item): array
    {
        if ($text !== null && trim($text) !== '') {
            if (preg_match('/^\s*\d+([.,]\d+)?\s*$/', $text) && $item->unit) {
                return [(float) str_replace(',', '.', trim($text)), $item->unit];
            }

            return Units::parse($text) ?? throw ValidationException::withMessages(['amount_text' => 'Say how much like "500 g", "1 l" or "6".']);
        }
        if ($product?->amount && $product->unit && (! $item->unit || Units::compatible($product->unit, $item->unit))) {
            return [$product->amount, $product->unit];
        }
        // A barcode remembered from an earlier pack: the same size again
        if ($item->pack_amount && $item->unit) {
            return [$item->pack_amount, $item->unit];
        }

        return [1.0, $item->unit ?? 'pcs'];
    }
}
