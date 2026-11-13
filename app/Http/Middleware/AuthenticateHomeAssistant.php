<?php

namespace App\Http\Middleware;

use App\SmartHome\Connections;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * State changes pushed by a household's Home Assistant (docs/smart-home.md#state-changes):
 * "Authorization: Bearer <connection id>.<webhook secret>". The secret is the
 * connection's own, not a user token, so it can only report states for that
 * one household, and rotating it doesn't log anybody out.
 *
 * The connection is put on the request as the `smart_home_connection` attribute.
 */
class AuthenticateHomeAssistant
{
    public function __construct(private Connections $connections) {}

    public function handle(Request $request, Closure $next): Response
    {
        $connection = $this->connections->forWebhook($request->bearerToken());
        if (! $connection) {
            Log::notice('Smart home webhook refused', ['ip' => $request->ip()]);

            return response()->json(['error' => 'invalid_webhook_token', 'message' => 'Use the webhook token Home Helper showed when you connected Home Assistant.'], 401);
        }
        $request->attributes->set('smart_home_connection', $connection);

        return $next($request);
    }
}
