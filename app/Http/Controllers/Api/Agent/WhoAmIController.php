<?php

namespace App\Http\Controllers\Api\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/agent/v1/me: who the server thinks this agent is. Used by
 * `home-helper-agent status` to check the credentials end to end.
 */
class WhoAmIController extends AgentController
{
    public function __invoke(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        return response()->json([
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'household' => ['id' => $agent->household->id, 'name' => $agent->household->name],
            'server_time' => time(),
        ]);
    }
}
