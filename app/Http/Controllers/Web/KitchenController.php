<?php

namespace App\Http\Controllers\Web;

use App\Enums\ItemCategory;
use App\Enums\StorageLocation;
use App\Http\Controllers\Api\KitchenController as Api;
use App\Http\Controllers\Api\ReceiptController as ReceiptApi;
use App\Kitchen\Pantry;
use App\Kitchen\Units;
use App\Models\InventoryItem;
use App\Models\KitchenMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Kitchen page (resources/js/pages/kitchen.tsx, docs/kitchen.md): what's
 * in the fridge, freezer and cupboards with amounts, using and adding, and
 * what moved recently. Later days of Phase 7 add scanning, receipts, expiry,
 * recipes and the meal plan to the same page.
 */
class KitchenController extends WebController
{
    public function __construct(private Pantry $pantry) {}

    public function show(Request $request): Response
    {
        $household = $this->household($request);

        return Inertia::render('kitchen', [
            'items' => $household->inventoryItems()->where('category', '!=', ItemCategory::Cleaning->value)
                ->orderBy('name')->get()->map(fn (InventoryItem $i) => self::item($i))->all(),
            'movements' => $household->kitchenMovements()->with(['item:id,name', 'user:id,name'])
                ->orderByDesc('occurred_at')->limit(30)->get()
                ->map(fn (KitchenMovement $m) => Api::movement($m))->all(),
            'locations' => StorageLocation::values(),
            // The newest draft receipt waiting to be checked (docs/kitchen.md#receipts)
            'receipt' => ($draft = $household->receipts()->where('status', 'draft')->latest()->first()) ? ReceiptApi::receipt($draft) : null,
            'photos' => config('homehelper.kitchen.receipts.ocr') !== 'none',
        ]);
    }

    /** POST /kitchen/items {name, location, amount_text?} */
    public function store(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location' => ['required', Rule::enum(StorageLocation::class)],
            'amount_text' => ['nullable', 'string', 'max:50'],
        ]);
        $parsed = Units::parse($data['amount_text'] ?? null);
        if (($data['amount_text'] ?? '') !== '' && ! $parsed) {
            throw ValidationException::withMessages(['amount_text' => 'Say how much like "500 g", "1 l" or "6".']);
        }
        if ($household->inventoryItems()->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => "{$data['name']} is already in the kitchen. Add to it instead."]);
        }

        DB::transaction(function () use ($household, $data, $parsed, $request) {
            $item = $household->inventoryItems()->create([
                'name' => $data['name'],
                'category' => self::category($data['location']),
                'location' => $data['location'],
            ]);
            if ($parsed && $parsed[0] > 0) {
                $this->pantry->add($item, $parsed[0], $parsed[1], $request->user(), 'added on the Kitchen page');
            }
        });

        return to_route('kitchen');
    }

    /** POST /kitchen/items/{item}/use {kind, amount_text?}: nothing = all of it */
    public function take(Request $request, string $item): RedirectResponse
    {
        $row = $this->find($request, $item);
        $data = $request->validate([
            'kind' => ['required', 'in:used,cooked,wasted,expired'],
            'amount_text' => ['nullable', 'string', 'max:50'],
        ]);
        [$amount, $unit] = $this->amount($data['amount_text'] ?? null, $row) ?? [null, null];
        $this->pantry->take($row, $data['kind'], $amount, $unit, $request->user());

        return to_route('kitchen');
    }

    /** POST /kitchen/items/{item}/add {amount_text} */
    public function add(Request $request, string $item): RedirectResponse
    {
        $row = $this->find($request, $item);
        $data = $request->validate(['amount_text' => ['required', 'string', 'max:50']]);
        [$amount, $unit] = $this->amount($data['amount_text'], $row) ?? throw ValidationException::withMessages(['amount_text' => 'Say how much like "500 g", "1 l" or "6".']);
        $this->pantry->add($row, $amount, $unit, $request->user());

        return to_route('kitchen');
    }

    /** POST /kitchen/items/{item}/set {amount_text}: "there are actually 3 left" */
    public function set(Request $request, string $item): RedirectResponse
    {
        $row = $this->find($request, $item);
        $data = $request->validate(['amount_text' => ['required', 'string', 'max:50']]);
        [$amount, $unit] = $this->amount($data['amount_text'], $row) ?? throw ValidationException::withMessages(['amount_text' => 'Say how much like "500 g", "1 l" or "6".']);
        $this->pantry->set($row, $amount, $unit, $request->user(), 'counted');

        return to_route('kitchen');
    }

    public function destroy(Request $request, string $item): RedirectResponse
    {
        $row = $this->find($request, $item);
        Gate::authorize('delete', $row);
        $row->delete();

        return to_route('kitchen');
    }

    /** @return array<string, mixed> */
    public static function item(InventoryItem $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'ingredient' => $i->ingredient,
            'category' => $i->category->value,
            'location' => $i->location?->value,
            'level' => $i->level?->value,
            'amount' => $i->amount,
            'unit' => $i->unit,
            'full_amount' => $i->full_amount,
            'amount_text' => $i->amountText(),
            'barcode' => $i->barcode,
            'expires_on' => $i->expires_on?->toDateString(),
        ];
    }

    /**
     * "200" for an item counted in grams means 200 g, not 200 pieces
     *
     * @return array{0: float, 1: string}|null
     */
    private function amount(?string $text, InventoryItem $item): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        if (preg_match('/^\s*\d+([.,]\d+)?\s*$/', $text) && $item->unit) {
            return [(float) str_replace(',', '.', trim($text)), $item->unit];
        }

        return Units::parse($text);
    }

    private function find(Request $request, string $id): InventoryItem
    {
        $item = $this->household($request)->inventoryItems()->findOrFail($id);
        Gate::authorize('update', $item);

        return $item;
    }

    private static function category(string $location): ItemCategory
    {
        return match ($location) {
            StorageLocation::Bathroom->value => ItemCategory::Bathroom,
            StorageLocation::Cleaning->value => ItemCategory::Cleaning,
            default => ItemCategory::Food,
        };
    }
}
