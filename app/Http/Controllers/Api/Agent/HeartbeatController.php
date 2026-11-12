<?php

namespace App\Http\Controllers\Api\Agent;

use App\Agents\Heartbeats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/agent/v1/heartbeat (agent/docs/protocol.md#post-heartbeat):
 * every 30 seconds, the agent's status and readings in, its work out.
 */
class HeartbeatController extends AgentController
{
    public function __invoke(Request $request, Heartbeats $heartbeats): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/'],
            'platform' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9]+-[a-z0-9]+$/'],
            'git_sha' => ['nullable', 'string', 'max:40'],
            'started_at' => ['nullable', 'date'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'inventory_hash' => ['nullable', 'string', 'size:128'],
            'outbox' => ['nullable', 'array:pending,dropped'],
            'outbox.pending' => ['integer', 'min:0'],
            'outbox.dropped' => ['integer', 'min:0'],

            'metrics' => ['nullable', 'array:cpu_percent,memory,disks,temperatures,load'],
            'metrics.cpu_percent' => ['nullable', 'numeric', 'between:0,100'],
            'metrics.memory' => ['nullable', 'array:total_bytes,used_bytes'],
            'metrics.memory.total_bytes' => ['integer', 'min:0'],
            'metrics.memory.used_bytes' => ['integer', 'min:0'],
            'metrics.disks' => ['nullable', 'array', 'max:32'],
            'metrics.disks.*' => ['array:mount,total_bytes,free_bytes'],
            'metrics.disks.*.mount' => ['required', 'string', 'max:255'],
            'metrics.disks.*.total_bytes' => ['required', 'integer', 'min:0'],
            'metrics.disks.*.free_bytes' => ['required', 'integer', 'min:0'],
            'metrics.temperatures' => ['nullable', 'array', 'max:32'],
            'metrics.temperatures.*' => ['array:label,celsius'],
            'metrics.temperatures.*.label' => ['required', 'string', 'max:80'],
            'metrics.temperatures.*.celsius' => ['required', 'numeric', 'between:-50,150'],
            'metrics.load' => ['nullable', 'array', 'max:3'],
            'metrics.load.*' => ['numeric', 'min:0'],
        ]);

        return response()->json($heartbeats->record($this->agent($request), $data, $request->ip()));
    }
}
