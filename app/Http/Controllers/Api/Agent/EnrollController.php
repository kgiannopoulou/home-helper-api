<?php

namespace App\Http\Controllers\Api\Agent;

use App\Agents\Enrollment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/agent/v1/enroll: a pairing code for an agent id and a secret
 * (agent/docs/protocol.md#post-enroll). The only unsigned agent endpoint;
 * the code is the proof, and the route is rate-limited per address.
 */
class EnrollController extends Controller
{
    public function __invoke(Request $request, Enrollment $enrollment): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:80'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9]+-[a-z0-9]+$/'],
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/'],
            'git_sha' => ['nullable', 'string', 'max:40', 'regex:/^[0-9a-f]+$|^unknown$/'],
        ]);

        $result = $enrollment->enroll($data['code'], $data, $request->ip());
        if (! $result) {
            Log::notice('Agent enrollment refused', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_code', 'message' => 'That pairing code is wrong, used or expired. Create a new one on the Computers page.'], 422);
        }

        [$agent, $secret] = $result;
        Log::info('Agent enrolled', ['agent_id' => $agent->id, 'household_id' => $agent->household_id, 'name' => $agent->name]);

        return response()->json([
            'agent_id' => $agent->id,
            'secret' => $secret,
            'household' => ['id' => $agent->household->id, 'name' => $agent->household->name],
            'heartbeat_seconds' => (int) config('homehelper.agents.heartbeat_seconds'),
        ], 201);
    }
}
