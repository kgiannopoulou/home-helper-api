<?php

namespace App\Http\Controllers\Api\Agent;

use App\Agents\Commands;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/agent/v1/commands/{command}/result (agent/docs/protocol.md):
 * what came of a command the agent was sent.
 */
class CommandResultController extends AgentController
{
    public function __invoke(Request $request, string $command, Commands $commands): Response
    {
        $data = $request->validate([
            'status' => ['required', 'in:succeeded,failed,timed_out,rejected'],
            'exit_code' => ['nullable', 'integer'],
            'output' => ['nullable', 'string', 'max:1100000'],
            'truncated' => ['boolean'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'started_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $commands->recordResult($this->agent($request), $command, $data);

        return response()->noContent();
    }
}
