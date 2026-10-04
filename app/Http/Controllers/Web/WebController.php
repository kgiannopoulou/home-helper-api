<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Dashboard pages. They read the same SQL queries as the API (app/Queries), so the
 * browser and the phone always agree. The household comes from EnsureHousehold.
 */
abstract class WebController extends Controller
{
    protected function household(Request $request): Household
    {
        return $request->attributes->get('household');
    }

    protected function today(): CarbonImmutable
    {
        return HomeTime::today();
    }
}
