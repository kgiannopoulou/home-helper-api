<?php

namespace App\Jobs;

use App\Briefing\BriefingService;
use App\Models\Household;
use App\Push\ExpoPush;
use Carbon\CarbonImmutable;

/**
 * Every morning (homehelper.briefing.time): each member's briefing is built,
 * saved, and its headline pushed to their phones. The full text is on
 * /briefing, in the API, and the assistant can read it out.
 */
class DailyBriefing extends HouseholdJob
{
    protected function run(Household $household, CarbonImmutable $today): array
    {
        $sent = [];
        foreach ($household->users as $user) {
            $briefing = app(BriefingService::class)->store($household, $user, $today);
            $pushed = app(ExpoPush::class)->toUsers([$user], "☀️ {$today->format('l')}: your day", $briefing->content['headline'], ['url' => '/briefing']);
            $briefing->update(['pushed' => $pushed['sent'] > 0]);

            $sent[] = [
                'user_id' => $user->id,
                'recommendations' => array_column($briefing->content['recommendations'], 'rule'),
                'unavailable' => $briefing->content['unavailable'],
                'push' => $pushed,
            ];
        }

        return ['members' => $sent];
    }
}
