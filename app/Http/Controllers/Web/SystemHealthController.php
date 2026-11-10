<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Ops\Alerts;
use App\Ops\SystemHealth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /system/health: every check with its details, the recent ops alerts and
 * facts about the running app. Admins only (OPS_ADMIN_EMAILS); the page
 * reloads its data every 15 seconds.
 */
class SystemHealthController extends Controller
{
    public function __invoke(SystemHealth $health): Response
    {
        return Inertia::render('system-health', [
            'health' => $health->run(),
            'app' => $health->app(),
            'alerts' => Alerts::recent(),
        ]);
    }
}
