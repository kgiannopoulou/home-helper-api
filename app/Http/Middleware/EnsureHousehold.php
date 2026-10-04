<?php

namespace App\Http\Middleware;

use App\Web\CurrentHousehold;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard pages need a household. Without one, you're sent to the Household page
 * to start one or join with an invite.
 */
class EnsureHousehold
{
    public function handle(Request $request, Closure $next): Response
    {
        $household = CurrentHousehold::get($request);
        if (! $household) {
            return redirect()->route('household.show');
        }
        $request->attributes->set('household', $household);

        return $next($request);
    }
}
