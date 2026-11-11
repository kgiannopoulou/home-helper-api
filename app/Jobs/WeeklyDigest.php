<?php

namespace App\Jobs;

use App\Memory\HouseholdDigest;
use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * Monday morning: last week (Monday to Sunday) written into long-term memory.
 */
class WeeklyDigest extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $summary = app(HouseholdDigest::class)->write($household, 'week', $today->subWeek());

        return ['summary_id' => $summary->id, 'starts_on' => $summary->starts_on->toDateString(), 'spent' => $summary->stats['spent']];
    }
}
