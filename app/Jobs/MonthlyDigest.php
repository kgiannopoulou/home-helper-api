<?php

namespace App\Jobs;

use App\Memory\HouseholdDigest;
use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * The 1st of the month: last month written into long-term memory.
 */
class MonthlyDigest extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $summary = app(HouseholdDigest::class)->write($household, 'month', $today->subMonthNoOverflow());

        return ['summary_id' => $summary->id, 'starts_on' => $summary->starts_on->toDateString(), 'spent' => $summary->stats['spent']];
    }
}
