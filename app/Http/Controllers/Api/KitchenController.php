<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Kitchen\Expiry;
use App\Kitchen\Pantry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\KitchenMovement;
use App\Queries\KitchenWasteQuery;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The kitchen beyond plain rows (docs/kitchen.md#api): amounts going in and
 * out of the inventory, and their history.
 */
class KitchenController extends Controller
{
    public function __construct(private Pantry $pantry) {}

    /**
     * POST inventory-items/{item}/use {kind: used|cooked|wasted|expired, amount?, unit?}
     *
     * Without an amount: all of it ("used it up", "threw it away").
     */
    public function take(Request $request, Household $household, string $item): JsonResponse
    {
        $row = $this->item($household, $item);
        $data = $request->validate([
            'kind' => ['sometimes', 'in:used,cooked,wasted,expired'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'unit' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $movement = $this->pantry->take($row, $data['kind'] ?? 'used', isset($data['amount']) ? (float) $data['amount'] : null, $data['unit'] ?? null, $request->user(), $data['note'] ?? '');

        return $this->answer($row, $movement);
    }

    /** POST inventory-items/{item}/add {amount, unit?}: bought or found more */
    public function add(Request $request, Household $household, string $item): JsonResponse
    {
        $row = $this->item($household, $item);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'unit' => ['nullable', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $movement = $this->pantry->add($row, (float) $data['amount'], $data['unit'] ?? $row->unit ?? 'pcs', $request->user(), $data['note'] ?? '');

        return $this->answer($row, $movement, 201);
    }

    /** GET kitchen/movements?item=&kind=: newest first, 100 at most */
    public function movements(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);
        $data = $request->validate([
            'item' => ['nullable', 'string', 'size:26'],
            'kind' => ['nullable', 'in:'.implode(',', KitchenMovement::KINDS)],
        ]);
        $rows = $household->kitchenMovements()->with(['item:id,name', 'user:id,name'])
            ->when($data['item'] ?? null, fn ($q, $id) => $q->where('inventory_item_id', $id))
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderByDesc('occurred_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (KitchenMovement $m) => self::movement($m))->all()]);
    }

    /** GET kitchen/expiring?days=7: in stock and going off within the days (or already), soonest first */
    public function expiring(Request $request, Household $household, Expiry $expiry): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:0,60']])['days'] ?? 7);
        $today = HomeTime::today();

        return response()->json(['data' => $expiry->expiring($household, $today, $days)
            ->map(fn (InventoryItem $i) => (new InventoryItemResource($i))->resolve() + ['expiry' => Expiry::status($i, $today)])->all()]);
    }

    /** GET kitchen/waste?days=30: what was thrown away, and roughly what it cost */
    public function waste(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:1,365']])['days'] ?? 30);

        return response()->json((new KitchenWasteQuery($household, HomeTime::today(), $days))->get());
    }

    /** POST inventory-items/{item}/opened: the date can only come closer */
    public function opened(Household $household, string $item, Expiry $expiry): JsonResponse
    {
        $row = $this->item($household, $item);
        $expiry->opened($row, HomeTime::today());
        $row->save();

        return response()->json(['item' => new InventoryItemResource($row)]);
    }

    /** POST inventory-items/{item}/expiry {expires_on}: the date printed on the pack */
    public function expiry(Request $request, Household $household, string $item, Expiry $expiry): JsonResponse
    {
        $row = $this->item($household, $item);
        $data = $request->validate(['expires_on' => ['required', 'date_format:Y-m-d']]);
        $expiry->set($row, CarbonImmutable::parse($data['expires_on'], HomeTime::zone()));

        return response()->json(['item' => new InventoryItemResource($row)]);
    }

    /** POST inventory-items/{item}/still-good: checked it; ask again in two days */
    public function stillGood(Household $household, string $item, Expiry $expiry): JsonResponse
    {
        $row = $this->item($household, $item);
        $expiry->stillGood($row, HomeTime::today());

        return response()->json(['item' => new InventoryItemResource($row)]);
    }

    /** @return array<string, mixed> */
    public static function movement(KitchenMovement $m): array
    {
        return [
            'id' => $m->id,
            'item_id' => $m->inventory_item_id,
            'item' => $m->item->name,
            'kind' => $m->kind,
            'amount' => $m->amount,
            'unit' => $m->unit,
            'label' => $m->label(),
            'by' => $m->user?->name,
            'note' => $m->note,
            'occurred_at' => $m->occurred_at->toIso8601String(),
        ];
    }

    private function item(Household $household, string $id): InventoryItem
    {
        $item = $household->inventoryItems()->findOrFail($id);
        Gate::authorize('update', $item);

        return $item;
    }

    private function answer(InventoryItem $item, KitchenMovement $movement, int $status = 200): JsonResponse
    {
        $movement->setRelation('item', $item);

        return response()->json([
            'item' => new InventoryItemResource($item->fresh()),
            'movement' => self::movement($movement->load('user:id,name')),
        ], $status);
    }
}
