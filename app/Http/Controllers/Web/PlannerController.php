<?php

namespace App\Http\Controllers\Web;

use App\Planning\ApplyPlan;
use App\Planning\WeekPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlannerController extends WebController
{
    public function show(Request $request): Response
    {
        return Inertia::render('planner', [
            'plan' => (new WeekPlanner($this->household($request), WeekPlanner::nextWeek($this->today())))->plan(),
        ]);
    }

    /**
     * The transaction endpoint: the ticked items go into the calendar, all or none.
     */
    public function apply(Request $request, ApplyPlan $apply): RedirectResponse
    {
        $data = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'keys' => ['required', 'array', 'min:1', 'max:200'],
            'keys.*' => ['required', 'string', 'distinct', 'max:100'],
        ]);

        $result = $apply($this->household($request), $request->user(), CarbonImmutable::parse($data['week_start']), $data['keys']);

        $message = $result['added'] === 1 ? 'Added 1 item to the calendar.' : "Added {$result['added']} items to the calendar.";
        if ($result['skipped']) {
            $message .= " {$result['skipped']} were there already.";
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
