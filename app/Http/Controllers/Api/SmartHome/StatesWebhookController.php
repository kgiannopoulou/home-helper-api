<?php

namespace App\Http\Controllers\Api\SmartHome;

use App\Http\Controllers\Controller;
use App\Models\SmartHomeConnection;
use App\SmartHome\StateChanges;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/smart-home/v1/states (docs/smart-home.md#state-changes): one state
 * change, as Home Assistant's forwarding automation sends it
 * (ops/home-assistant/config/packages/home_helper.yaml).
 */
class StatesWebhookController extends Controller
{
    public function __invoke(Request $request, StateChanges $changes): JsonResponse
    {
        /** @var SmartHomeConnection $connection */
        $connection = $request->attributes->get('smart_home_connection');

        $data = $request->validate([
            'entity_id' => ['required', 'string', 'max:255', 'regex:/^[a-z_]+\.[a-z0-9_]+$/'],
            'state' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'array'],
            'last_changed' => ['nullable', 'date'],
            'old_state' => ['nullable', 'string', 'max:255'],
        ]);

        $connection->forceFill(['last_event_at' => CarbonImmutable::now()])->save();
        $device = $changes->applyPushed($connection, $data['entity_id'], $data['state'] ?? null, $data['attributes'] ?? [], $data['last_changed'] ?? null);

        // Unknown entity: a new device, found by the next sync (within a minute)
        return response()->json(['accepted' => true, 'known' => $device !== null], 202);
    }
}
