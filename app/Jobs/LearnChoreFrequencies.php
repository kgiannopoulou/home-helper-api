<?php

namespace App\Jobs;

use App\Models\Household;
use App\Queries\SlippingChoresQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily: chores you keep doing 30% late (or early) move one step to a frequency
 * that matches what you really do (the Phase 3 query). It remembers the old
 * frequency, so the phone can offer "Keep" or "Undo"; Undo fixes the frequency
 * and this job leaves that chore alone from then on.
 */
class LearnChoreFrequencies extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $changes = (new SlippingChoresQuery($household, $today))->get();

        DB::transaction(function () use ($household, $changes, $today) {
            foreach ($changes as $c) {
                $household->chores()->whereKey($c['id'])->first()?->update([
                    'every_days' => $c['suggested_every_days'],
                    'learned_from_days' => $c['every_days'],
                    'learned_on' => $today->toDateString(),
                ]);
            }
        });

        return [
            'changed' => array_map(fn (array $c) => [
                'chore' => "{$c['room']}: {$c['name']}",
                'from' => $c['every_days'],
                'to' => $c['suggested_every_days'],
                'trend' => $c['trend'],
            ], $changes),
        ];
    }
}
