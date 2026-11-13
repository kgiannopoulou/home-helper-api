<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\ReceiptController as Api;
use App\Kitchen\Receipts\Receipts;
use App\Models\InventoryItem;
use App\Models\Receipt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The Kitchen page's receipt card: read, check, import */
class ReceiptController extends WebController
{
    public function __construct(private Receipts $receipts) {}

    public function store(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(Api::rules());
        $this->receipts->read($household, $request->user(), $request->file('photo'), $data['text'] ?? null);

        return to_route('kitchen');
    }

    public function import(Request $request, string $receipt): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(Api::importRules());
        $this->receipts->import($this->find($request, $receipt), $request->user(), $data['lines'] ?? [], $data);

        return to_route('kitchen');
    }

    public function destroy(Request $request, string $receipt): RedirectResponse
    {
        Gate::authorize('create', [InventoryItem::class, $this->household($request)]);
        $this->receipts->discard($this->find($request, $receipt));

        return to_route('kitchen');
    }

    private function find(Request $request, string $id): Receipt
    {
        return Receipt::query()->where('household_id', $this->household($request)->id)->findOrFail($id);
    }
}
