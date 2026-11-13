<?php

namespace App\Kitchen\Receipts;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseSource;
use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Enums\TripSource;
use App\Kitchen\Ingredients;
use App\Kitchen\Pantry;
use App\Kitchen\Units;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Receipt;
use App\Models\ShoppingItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Receipts, from photo to kitchen (docs/kitchen.md#receipts):
 *
 *   read()    photo → Tesseract → text, or pasted text → ReceiptParser →
 *             a draft, each line matched to the household's own item with
 *             the same ingredient. Nothing else changes yet.
 *   import()  what the person ticked and corrected, in one transaction:
 *             the shopping trip, the grocery expense, a purchase and a price
 *             per line, the amounts into the kitchen, and the shopping list
 *             items it covers checked off.
 *   discard() the draft and its photo go.
 */
class Receipts
{
    public function __construct(private Ocr $ocr, private ReceiptParser $parser, private Pantry $pantry) {}

    public function read(Household $household, User $user, ?UploadedFile $photo, ?string $text): Receipt
    {
        $receipt = new Receipt(['status' => 'draft', 'user_id' => $user->id, 'source' => $photo ? 'photo' : 'text', 'lines' => [], 'ocr_text' => '']);
        $receipt->household_id = $household->id;
        $receipt->id = (string) $receipt->newUniqueId();

        if ($photo) {
            $receipt->image_path = $photo->storeAs("receipts/{$household->id}", $receipt->id.'.'.($photo->extension() ?: 'jpg'), 'local') ?: null;
            try {
                $text = $this->ocr->text(Storage::disk('local')->path((string) $receipt->image_path));
            } catch (OcrFailed $e) {
                $receipt->forgetImage();
                throw ValidationException::withMessages(['photo' => $e->getMessage()]);
            }
        }
        $text = Receipt::scrub((string) $text);
        $parsed = $this->parser->parse($text);
        if ($parsed->lines === []) {
            $receipt->forgetImage();
            throw ValidationException::withMessages([$photo ? 'photo' : 'text' => 'No items found. Try a sharper, straight photo in good light, or paste the text.']);
        }

        $receipt->forceFill([
            'store' => $parsed->store,
            'purchased_on' => $parsed->purchasedOn,
            'total' => $parsed->total ?? $parsed->sum,
            'adds_up' => $parsed->addsUp,
            'ocr_text' => mb_substr($text, 0, 20000),
            'lines' => $this->match($household, $parsed->lines),
        ])->save();
        Log::info('Receipt read', ['receipt_id' => $receipt->id, 'source' => $receipt->source, 'lines' => count($parsed->lines), 'adds_up' => $parsed->addsUp]);

        return $receipt;
    }

    /**
     * @param  array<int, array{include?: bool, item_id?: string|null, name?: string|null, amount_text?: string|null, location?: string|null}>  $choices  by line index; a line not mentioned keeps its suggestion
     * @param  array{store?: string|null, purchased_on?: string|null}  $header
     */
    public function import(Receipt $receipt, User $user, array $choices = [], array $header = []): Receipt
    {
        if ($receipt->status !== 'draft') {
            throw ValidationException::withMessages(['receipt' => 'This receipt has already been '.$receipt->status.'.']);
        }
        $household = Household::query()->findOrFail($receipt->household_id);

        return DB::transaction(function () use ($receipt, $user, $choices, $header, $household) {
            $receipt = Receipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $date = CarbonImmutable::parse($header['purchased_on'] ?? $receipt->purchased_on ?? CarbonImmutable::today());
            $store = trim((string) ($header['store'] ?? $receipt->store)) ?: null;
            $lines = $receipt->lines;

            $trip = $household->shoppingTrips()->create([
                'user_id' => $user->id,
                'date' => $date,
                'store' => $store ? mb_substr($store, 0, 100) : null,
                'total' => (float) $receipt->total,
                'item_count' => count($lines),
                'source' => TripSource::Receipt,
            ]);
            $household->expenses()->create([
                'user_id' => $user->id,
                'date' => $date,
                'amount' => (float) $receipt->total,
                'category' => ExpenseCategory::Groceries,
                'note' => $store,
                'source' => ExpenseSource::Shopping,
            ]);

            $listed = $household->shoppingItems()->where('checked', false)->get();
            foreach ($lines as $i => $line) {
                $choice = $choices[$i] ?? [];
                if (! ($choice['include'] ?? $line['include'])) {
                    continue;
                }
                $item = $this->item($household, $line, $choice);
                $household->purchases()->create([
                    'inventory_item_id' => $item->id,
                    'shopping_trip_id' => $trip->id,
                    'bought_on' => $date,
                    'price' => $line['price'],
                ]);
                $count = $line['weighed'] ? 1 : max(1, (int) ($line['quantity'] ?? 1));
                $household->itemPrices()->create([
                    'shopping_trip_id' => $trip->id,
                    'name' => mb_strtolower($item->name),
                    'price' => round($line['price'] / $count, 2),
                    'seen_on' => $date,
                ]);
                $amount = $this->amount($choice['amount_text'] ?? null, $line, $item);
                if ($amount) {
                    $this->pantry->add($item, $amount[0], $amount[1], $user, 'receipt'.($store ? " from {$store}" : ''), $date->setTimeFrom(CarbonImmutable::now()));
                } else {
                    // Bought, size unknown: at least it isn't empty any more
                    $item->forceFill(['level' => StockLevel::Full])->save();
                }

                // Bought: off the list
                $listed->filter(fn (ShoppingItem $s) => ! $s->checked && Ingredients::key($s->name) === $item->ingredient)
                    ->each(fn (ShoppingItem $s) => $s->forceFill(['checked' => true])->save());
            }

            $receipt->forgetImage();
            $receipt->forceFill(['status' => 'imported', 'shopping_trip_id' => $trip->id, 'imported_at' => CarbonImmutable::now(), 'store' => $store, 'purchased_on' => $date])->save();

            return $receipt;
        });
    }

    public function discard(Receipt $receipt): void
    {
        $receipt->forgetImage();
        $receipt->forceFill(['status' => 'discarded'])->save();
    }

    /**
     * Each line with the household's item it most likely is, and whether it goes to the kitchen
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function match(Household $household, array $lines): array
    {
        $items = $household->inventoryItems()->get(['id', 'name', 'ingredient']);

        return array_map(function (array $line) use ($items) {
            $item = $items->first(fn (InventoryItem $i) => $i->ingredient === $line['ingredient']);

            return $line + ['item_id' => $item?->id, 'item_name' => $item?->name, 'include' => (bool) $line['food']];
        }, $lines);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $choice
     */
    private function item(Household $household, array $line, array $choice): InventoryItem
    {
        $id = array_key_exists('item_id', $choice) ? $choice['item_id'] : $line['item_id'];
        if ($id) {
            return $household->inventoryItems()->findOrFail((string) $id);
        }
        $name = trim((string) ($choice['name'] ?? '')) ?: $line['name'];

        return $household->inventoryItems()->where('name', $name)->first() ?? $household->inventoryItems()->create([
            'name' => mb_substr($name, 0, 100),
            'ingredient' => $line['ingredient'],
            'category' => $line['food'] ? ItemCategory::Food : ItemCategory::Home,
            'location' => $choice['location'] ?? ($line['food'] ? StorageLocation::Pantry->value : StorageLocation::Other->value),
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{0: float, 1: string}|null null: bought, but how much is unknown
     */
    private function amount(?string $text, array $line, InventoryItem $item): ?array
    {
        if ($text !== null && trim($text) !== '') {
            if (preg_match('/^\s*\d+([.,]\d+)?\s*$/', $text) && $item->unit) {
                return [(float) str_replace(',', '.', trim($text)), $item->unit];
            }

            return Units::parse($text) ?? throw ValidationException::withMessages(['amount_text' => 'Say how much like "500 g", "1 l" or "6".']);
        }
        if ($line['amount'] && $line['unit'] && (! $item->unit || Units::compatible($line['unit'], $item->unit))) {
            return [(float) $line['amount'], (string) $line['unit']];
        }
        // Two of a pack whose size the receipt didn't say: two of the size we know
        $count = $line['weighed'] ? 1 : max(1, (float) ($line['quantity'] ?? 1));
        $pack = $item->pack_amount ?? $item->full_amount;
        if ($pack && $item->unit) {
            return [$pack * $count, $item->unit];
        }

        // Counted in pieces (or not measured yet): the count; weighed with no size known: nothing to add
        return in_array($item->unit, [null, 'pcs'], true) ? [$count, 'pcs'] : null;
    }
}
