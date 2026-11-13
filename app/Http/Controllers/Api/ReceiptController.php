<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kitchen\Receipts\Receipts;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Receipts from the phone (docs/kitchen.md#receipts): send a photo or the
 * text, check the draft, import it.
 */
class ReceiptController extends Controller
{
    public function __construct(private Receipts $receipts) {}

    /** POST kitchen/receipts: multipart `photo` (jpg, png, webp, 10 MB) or `text` */
    public function store(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(self::rules());
        $receipt = $this->receipts->read($household, $request->user(), $request->file('photo'), $data['text'] ?? null);

        return response()->json(['receipt' => self::receipt($receipt)], 201);
    }

    public function show(Household $household, string $receipt): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);

        return response()->json(['receipt' => self::receipt($this->find($household, $receipt))]);
    }

    /** POST kitchen/receipts/{receipt}/import {store?, purchased_on?, lines?: {index: {include, item_id, name, amount_text, location}}} */
    public function import(Request $request, Household $household, string $receipt): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(self::importRules());
        $row = $this->receipts->import($this->find($household, $receipt), $request->user(), $data['lines'] ?? [], $data);

        return response()->json(['receipt' => self::receipt($row)]);
    }

    public function destroy(Household $household, string $receipt): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $this->receipts->discard($this->find($household, $receipt));

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'photo' => ['required_without:text', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'text' => ['required_without:photo', 'nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function importRules(): array
    {
        return [
            'store' => ['nullable', 'string', 'max:100'],
            'purchased_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:tomorrow'],
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.include' => ['sometimes', 'boolean'],
            'lines.*.item_id' => ['nullable', 'string', 'size:26'],
            'lines.*.name' => ['nullable', 'string', 'max:100'],
            'lines.*.amount_text' => ['nullable', 'string', 'max:50'],
            'lines.*.location' => ['nullable', 'in:fridge,freezer,pantry,other'],
        ];
    }

    /** @return array<string, mixed> */
    public static function receipt(Receipt $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'source' => $r->source,
            'store' => $r->store,
            'purchased_on' => $r->purchased_on?->toDateString(),
            'total' => $r->total,
            'adds_up' => $r->adds_up,
            'lines' => array_map(fn (array $l, int $i) => [
                'index' => $i,
                'name' => $l['name'],
                'raw' => $l['raw'],
                'price' => $l['price'],
                'discount' => $l['discount'],
                'quantity' => $l['quantity'],
                'amount' => $l['amount'],
                'unit' => $l['unit'],
                'ingredient' => $l['ingredient'],
                'food' => $l['food'],
                'include' => $l['include'],
                'item_id' => $l['item_id'],
                'item_name' => $l['item_name'],
            ], $r->lines, array_keys($r->lines)),
            'shopping_trip_id' => $r->shopping_trip_id,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }

    private function find(Household $household, string $id): Receipt
    {
        return Receipt::query()->where('household_id', $household->id)->findOrFail($id);
    }
}
