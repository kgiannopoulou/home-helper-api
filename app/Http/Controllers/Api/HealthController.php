<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Ops\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * For an uptime monitor: 200 while the app works (ok or degraded), 503 when
 * it's down (the database is unreachable). Anyone gets each check's status;
 * an admin's token (OPS_ADMIN_EMAILS) also gets the summaries and details.
 * /up stays as the plain "PHP answers" check for load balancers.
 */
class HealthController extends Controller
{
    /**
     * System health.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, SystemHealth $health): JsonResponse
    {
        $report = $health->run();
        $user = auth('sanctum')->user();
        $admin = $user && Gate::forUser($user)->allows('viewSystemHealth');

        $body = $admin ? $report + ['app' => $health->app()] : [
            'status' => $report['status'],
            'checked_at' => $report['checked_at'],
            'checks' => array_map(fn (array $c) => ['status' => $c['status']], $report['checks']),
        ];

        return response()->json($body, $report['status'] === 'down' ? 503 : 200);
    }
}
