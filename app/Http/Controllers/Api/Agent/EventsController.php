<?php

namespace App\Http\Controllers\Api\Agent;

use App\Agents\AgentEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/agent/v1/events (agent/docs/protocol.md#post-events): up to 100
 * events from the agent's outbox, oldest first. Resends are recognised by id.
 */
class EventsController extends AgentController
{
    public function __invoke(Request $request, AgentEvents $events): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*' => ['array:id,type,severity,message,data,occurred_at'],
            'events.*.id' => ['required', 'uuid'],
            'events.*.type' => ['required', 'string', 'max:40', 'regex:/^[a-z]+(\.[a-z_]+)+$/'],
            'events.*.severity' => ['required', 'in:info,warning,critical'],
            'events.*.message' => ['required', 'string', 'max:1000'],
            'events.*.data' => ['nullable', 'array'],
            'events.*.occurred_at' => ['required', 'date'],
        ]);

        return response()->json($events->ingest($this->agent($request), array_values($data['events'])));
    }
}
