<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\Request;

/**
 * Base for the endpoints the local agent calls (agent/docs/protocol.md).
 * App\Http\Middleware\AuthenticateAgent has checked the signature and put
 * the agent on the request.
 */
abstract class AgentController extends Controller
{
    protected function agent(Request $request): Agent
    {
        return $request->attributes->get('agent');
    }
}
