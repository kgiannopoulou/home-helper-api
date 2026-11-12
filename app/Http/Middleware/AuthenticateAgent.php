<?php

namespace App\Http\Middleware;

use App\Agents\RequestSignature;
use App\Models\Agent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the local agent by its request signature and signs the
 * response (agent/docs/protocol.md#signatures). In order:
 *
 *   1. the four X-HH-* headers are present and well formed
 *   2. the agent exists and is not revoked
 *   3. the timestamp is within homehelper.agents.clock_skew_seconds
 *   4. the HMAC matches (constant time)
 *   5. the nonce has not been used in the last homehelper.agents.nonce_minutes
 *
 * The nonce is only spent after the signature checks out, so nobody can burn
 * an agent's nonces with forged requests. The agent is put on the request as
 * the `agent` attribute.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $agentId = (string) $request->header(RequestSignature::HEADER_AGENT, '');
        $timestamp = (string) $request->header(RequestSignature::HEADER_TIMESTAMP, '');
        $nonce = strtolower((string) $request->header(RequestSignature::HEADER_NONCE, ''));
        $signature = (string) $request->header(RequestSignature::HEADER_SIGNATURE, '');

        if (! preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/i', $agentId) || ! preg_match('/^\d{1,12}$/', $timestamp)
            || ! preg_match('/^[0-9a-f]{32}$/', $nonce) || ! preg_match('/^[0-9a-fA-F]{128}$/', $signature)) {
            return $this->reject('missing_signature', 'Signed requests only (agent/docs/protocol.md).');
        }

        $agent = Agent::query()->find($agentId);
        if (! $agent) {
            return $this->reject('unknown_agent', 'This computer is not enrolled.');
        }

        // From here on, rejections are signed too: the agent can trust "revoked" and "clock_skew"
        if ($agent->isRevoked()) {
            return $this->sign($agent, $nonce, $this->reject('agent_revoked', 'This computer was removed from the household.'));
        }

        $skew = abs(time() - (int) $timestamp);
        if ($skew > (int) config('homehelper.agents.clock_skew_seconds')) {
            return $this->sign($agent, $nonce, $this->reject('clock_skew', 'The clock on this computer is off by '.$skew.' seconds.', ['server_time' => time()]));
        }

        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if (! RequestSignature::verifyRequest($agent->secretBytes(), $signature, $request->method(), $path, $timestamp, $nonce, $agent->id, $request->getContent())) {
            Log::warning('Agent request with a bad signature', ['agent_id' => $agent->id, 'path' => $path, 'ip' => $request->ip()]);

            return $this->reject('bad_signature', 'The signature does not match.');
        }

        if (! Cache::add("agent-nonce:{$agent->id}:{$nonce}", 1, now()->addMinutes((int) config('homehelper.agents.nonce_minutes')))) {
            Log::warning('Agent request replayed', ['agent_id' => $agent->id, 'path' => $path, 'ip' => $request->ip()]);

            return $this->sign($agent, $nonce, $this->reject('replayed', 'This request was already received.'));
        }

        $request->attributes->set('agent', $agent);

        return $this->sign($agent, $nonce, $next($request));
    }

    /** @param array<string, mixed> $extra */
    private function reject(string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message] + $extra, 401);
    }

    private function sign(Agent $agent, string $nonce, Response $response): Response
    {
        $response->headers->set(
            RequestSignature::HEADER_SIGNATURE,
            RequestSignature::signResponse($agent->secretBytes(), $response->getStatusCode(), $nonce, (string) $response->getContent()),
        );

        return $response;
    }
}
