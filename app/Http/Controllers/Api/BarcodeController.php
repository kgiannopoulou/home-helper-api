<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Kitchen\Barcodes\ProductInfo;
use App\Kitchen\Barcodes\Scanner;
use App\Models\Household;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Barcodes from the phone (docs/kitchen.md#barcodes): look one up, then add
 * or use a pack.
 */
class BarcodeController extends Controller
{
    public function __construct(private Scanner $scanner) {}

    /** GET kitchen/barcodes/{code}: the household's item, a suggestion, the product */
    public function show(Household $household, string $code): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);

        return response()->json(self::scan($this->scanner->scan($household, $code)));
    }

    /** POST kitchen/barcodes/{code} {action: add|use, item_id?, name?, location?, amount_text?} */
    public function apply(Request $request, Household $household, string $code): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(self::rules());
        $item = $this->scanner->apply($household, $request->user(), $code, $data['action'], $data);

        return response()->json(['item' => new InventoryItemResource($item)], $data['action'] === 'add' ? 201 : 200);
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'action' => ['required', 'in:add,use'],
            'item_id' => ['nullable', 'string', 'size:26'],
            'name' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'in:fridge,freezer,pantry,other'],
            'amount_text' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @param  array{gtin: string, item: InventoryItem|null, suggestion: InventoryItem|null, product: ProductInfo|null, lookup_error: string|null}  $scan
     * @return array<string, mixed>
     */
    public static function scan(array $scan): array
    {
        return [
            'gtin' => $scan['gtin'],
            'item' => $scan['item'] ? (new InventoryItemResource($scan['item']))->resolve() : null,
            'suggestion' => $scan['suggestion'] ? (new InventoryItemResource($scan['suggestion']))->resolve() : null,
            'product' => $scan['product']?->toArray(),
            'lookup_error' => $scan['lookup_error'],
        ];
    }
}
