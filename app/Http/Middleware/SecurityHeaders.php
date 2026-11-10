<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (docs/security.md).
 *
 * The API only ever returns JSON, so it gets a CSP that allows nothing: a
 * response opened in a browser can't run script or be framed. The web pages
 * get no CSP here, because Inertia and the Vite dev server need inline and
 * eval'd scripts; they get the framing and sniffing protections. HSTS is only
 * sent over HTTPS in production, so a local http:// setup is never pinned.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->is('api/*')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
            $headers->set('X-Frame-Options', 'DENY');
            // Personal data: never kept by a shared cache or proxy
            if (! $headers->has('Cache-Control') || $headers->get('Cache-Control') === 'no-cache, private') {
                $headers->set('Cache-Control', 'no-store, private');
            }
        } else {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
