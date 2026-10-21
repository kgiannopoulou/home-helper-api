<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A unique key said no (the same item name twice, a second sleep entry for a night…)
        $exceptions->render(fn (UniqueConstraintViolationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'That already exists.', 'code' => 'conflict'], 409)
            : null);

        // A CHECK constraint said no: validation should catch it first, this is the safety net
        $exceptions->render(fn (QueryException $e, Request $request) => $request->is('api/*') && ($e->errorInfo[1] ?? null) === 3819
            ? response()->json(['message' => 'That value is not allowed.', 'code' => 'constraint_violation'], 422)
            : null);

        // One error shape for the whole API (docs/api.md):
        //   {"message": "…", "code": "validation_failed", "errors": {"field": ["…"]}, "request_id": "…"}
        // `message` and `errors` stay where Laravel puts them, so existing
        // clients (and assertJsonValidationErrors) keep working; `code` is
        // the stable value to branch on.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
                return $response;
            }

            $status = $response->getStatusCode();
            $body = (array) $response->getData(true);

            $body['code'] ??= match ($status) {
                400 => 'bad_request',
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                419 => 'session_expired',
                422 => 'validation_failed',
                429 => 'too_many_requests',
                503 => 'unavailable',
                default => $status >= 500 ? 'server_error' : 'error',
            };

            // "No query results for model [App\Models\Expense] 01J…" names internals
            if ($status === 404) {
                $body['message'] = 'Not found.';
            }
            if ($status === 429) {
                $body['message'] = 'Too many requests. Try again in a moment.';
            }

            $id = $request->attributes->get('request_id') ?? (string) Str::uuid();
            $body['request_id'] = $id;
            $response->headers->set('X-Request-Id', $id);

            return $response->setData($body);
        });
    })->create();
