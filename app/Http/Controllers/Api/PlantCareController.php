<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Plant;
use App\Plants\PlantCareService;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Plant care worked out on the server, so the phone shows it without
 * repeating the rules.
 */
class PlantCareController extends Controller
{
    private const MAX_PLAN_DAYS = 92;

    public function __construct(private PlantCareService $care) {}

    /**
     * What needs attention today: watering with today's amount, fertilizer and
     * repotting that's due, weather warnings for outdoor plants, and the week ahead.
     */
    public function today(Household $household): JsonResponse
    {
        Gate::authorize('viewAny', [Plant::class, $household]);

        return response()->json(['data' => $this->care->today($household)]);
    }

    /**
     * Plant care while you're away: each plant's waterings and fertilizer
     * between two days, for whoever looks after them.
     */
    public function plan(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('viewAny', [Plant::class, $household]);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'], HomeTime::zone());
        $to = CarbonImmutable::parse($data['to'], HomeTime::zone());
        if ($from->addDays(self::MAX_PLAN_DAYS) < $to) {
            throw ValidationException::withMessages(['to' => 'A plan covers at most '.self::MAX_PLAN_DAYS.' days.']);
        }

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'plants' => $this->care->plan($household, $from, $to),
        ]]);
    }
}
