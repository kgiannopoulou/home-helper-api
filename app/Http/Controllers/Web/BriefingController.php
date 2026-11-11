<?php

namespace App\Http\Controllers\Web;

use App\Briefing\BriefingService;
use App\Models\HouseholdSummary;
use App\Models\RecommendationFeedback;
use App\Queries\TimelineQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Today page (resources/js/pages/briefing.tsx): this morning's briefing
 * with its recommendations, the activity timeline and the household summaries.
 */
class BriefingController extends WebController
{
    public function show(Request $request, BriefingService $briefings): Response
    {
        $household = $this->household($request);
        $today = $this->today();
        $filters = $request->validate([
            'kind' => ['nullable', Rule::in(TimelineQuery::kinds())],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return Inertia::render('briefing', [
            'briefing' => $briefings->build($household, $request->user(), $today),
            'timeline' => (new TimelineQuery($household, $today, $request->user(), $today->subDays(14), $today,
                isset($filters['kind']) ? [$filters['kind']] : null, $filters['q'] ?? null, 60))->get(),
            'kinds' => TimelineQuery::kinds(),
            'filters' => ['kind' => $filters['kind'] ?? null, 'q' => $filters['q'] ?? ''],
            'summaries' => HouseholdSummary::query()->whereBelongsTo($household)->orderByDesc('starts_on')->limit(8)->get()
                ->map(fn (HouseholdSummary $s) => ['id' => $s->id, 'period' => $s->period, 'label' => $s->label(), 'text' => $s->text])->all(),
        ]);
    }

    public function feedback(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:150', 'regex:/^[a-z_]+:/'],
            'verdict' => ['required', Rule::in(['useful', 'dismissed'])],
        ]);
        RecommendationFeedback::record($this->household($request), $request->user(), $data['key'], $data['verdict'], $this->today());

        return back();
    }
}
