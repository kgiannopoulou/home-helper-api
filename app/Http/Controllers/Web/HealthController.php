<?php

namespace App\Http\Controllers\Web;

use App\Queries\HealthWeeksQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Your own sleep, steps and workouts. Like on the phone, other members never see them.
 */
class HealthController extends WebController
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('health', [
            'weeks' => (new HealthWeeksQuery($this->household($request), $this->today(), $request->user(), 8))->get(),
        ]);
    }
}
