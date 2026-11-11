<?php

namespace App\Http\Controllers\Api;

use App\Briefing\BriefingService;
use App\Http\Controllers\Controller;
use App\Models\Briefing;
use App\Models\Household;
use App\Models\HouseholdSummary;
use App\Models\RecommendationFeedback;
use App\Queries\TimelineQuery;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Household intelligence over the API (docs/briefings.md#api): the daily
 * briefing, recommendations and feedback on them, the activity timeline and
 * the weekly and monthly summaries.
 */
class BriefingController extends Controller
{
    /**
     * Your briefing. Today's is built fresh (the data moves during the day);
     * an earlier day's is the one that was sent that morning.
     */
    public function briefing(Request $request, Household $household, BriefingService $briefings): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.HomeTime::today()->toDateString()]]);
        $today = HomeTime::today();
        $date = isset($data['date']) ? CarbonImmutable::parse($data['date'], HomeTime::zone()) : $today;

        if ($date->lt($today)) {
            $sent = Briefing::query()->whereBelongsTo($household)->where('user_id', $request->user()->id)
                ->whereDate('date', $date->toDateString())->firstOrFail();

            return response()->json(['data' => $sent->content, 'text' => $sent->text, 'sent_at' => $sent->created_at->toIso8601String()]);
        }

        $content = $briefings->build($household, $request->user(), $today);

        return response()->json(['data' => $content, 'text' => BriefingService::text($content), 'sent_at' => null]);
    }

    /** Just the recommendations, as in the briefing */
    public function recommendations(Request $request, Household $household, BriefingService $briefings): JsonResponse
    {
        return response()->json(['data' => $briefings->build($household, $request->user(), HomeTime::today())['recommendations']]);
    }

    /**
     * "Useful" or "not now" on one recommendation. Not now hides it for a
     * week; either one moves that kind of suggestion up or down for you.
     */
    public function feedback(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:150', 'regex:/^[a-z_]+:/'],
            'verdict' => ['required', Rule::in(['useful', 'dismissed'])],
        ]);

        $feedback = RecommendationFeedback::record($household, $request->user(), $data['key'], $data['verdict'], HomeTime::today());

        return response()->json(['data' => [
            'key' => $feedback->key,
            'verdict' => $feedback->verdict,
            'hidden_until' => $feedback->hidden_until?->toDateString(),
        ]]);
    }

    /**
     * What happened, newest first, across every module. Days are at home.
     */
    public function timeline(Request $request, Household $household): JsonResponse
    {
        $today = HomeTime::today();
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'kinds' => ['sometimes', 'array'],
            'kinds.*' => [Rule::in(TimelineQuery::kinds())],
            'q' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
        ]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $today->subDays(30);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $today;

        $rows = (new TimelineQuery($household, $today, $request->user(), $from, $to, $data['kinds'] ?? null, $data['q'] ?? null, (int) ($data['limit'] ?? 50)))->get();

        return response()->json(['data' => $rows, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'kinds' => TimelineQuery::kinds()]);
    }

    /** The weekly and monthly summaries, newest first */
    public function summaries(Request $request, Household $household): JsonResponse
    {
        $data = $request->validate(['period' => ['sometimes', Rule::in(['week', 'month'])]]);

        return response()->json(['data' => HouseholdSummary::query()->whereBelongsTo($household)
            ->when($data['period'] ?? null, fn ($q, $p) => $q->where('period', $p))
            ->orderByDesc('starts_on')->limit(60)->get()
            ->map(fn (HouseholdSummary $s) => [
                'id' => $s->id,
                'period' => $s->period,
                'label' => $s->label(),
                'starts_on' => $s->starts_on->toDateString(),
                'ends_on' => $s->ends_on->toDateString(),
                'text' => $s->text,
                'stats' => $s->stats,
            ])->all()]);
    }
}
