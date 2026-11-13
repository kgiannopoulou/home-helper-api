<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\BarcodeController as Api;
use App\Kitchen\Barcodes\Scanner;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Kitchen page's scanner (resources/js/components/kitchen/barcode-scanner.tsx):
 * JSON for the lookup, a redirect after adding, like the rest of the page.
 */
class BarcodeController extends WebController
{
    public function __construct(private Scanner $scanner) {}

    public function show(Request $request, string $code): JsonResponse
    {
        $household = $this->household($request);
        Gate::authorize('viewAny', [InventoryItem::class, $household]);

        return response()->json(Api::scan($this->scanner->scan($household, $code)));
    }

    public function apply(Request $request, string $code): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(Api::rules());
        $this->scanner->apply($household, $request->user(), $code, $data['action'], $data);

        return to_route('kitchen');
    }
}
