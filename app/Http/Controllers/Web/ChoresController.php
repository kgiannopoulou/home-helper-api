<?php

namespace App\Http\Controllers\Web;

use App\Queries\FairShareQuery;
use App\Queries\OverdueChoresQuery;
use App\Queries\SlippingChoresQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChoresController extends WebController
{
    public const FAIR_SHARE_DAYS = 30;

    public function __invoke(Request $request): Response
    {
        $household = $this->household($request);
        $today = $this->today();

        return Inertia::render('chores', [
            'overdue' => (new OverdueChoresQuery($household, $today))->get(),
            'slipping' => (new SlippingChoresQuery($household, $today))->get(),
            'fairShare' => (new FairShareQuery($household, $today, self::FAIR_SHARE_DAYS))->get(),
            'days' => self::FAIR_SHARE_DAYS,
        ]);
    }
}
