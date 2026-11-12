<?php

namespace App\Console\Commands;

use App\Agents\AgentEvents;
use App\Models\Agent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * php artisan agents:check-offline — every minute (routes/console.php):
 * a computer that missed homehelper.agents.offline_after_heartbeats
 * heartbeats gets one agent.offline event, which also reaches the phones.
 * Its next heartbeat records agent.online (App\Agents\Heartbeats).
 */
class AgentsCheckOffline extends Command
{
    protected $signature = 'agents:check-offline';

    protected $description = 'Record computers that stopped checking in';

    public function handle(AgentEvents $events): int
    {
        $grace = (int) config('homehelper.agents.heartbeat_seconds') * (int) config('homehelper.agents.offline_after_heartbeats');
        $now = CarbonImmutable::now();

        $quiet = Agent::query()
            ->active()
            ->whereNull('offline_reported_at')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $now->subSeconds($grace))
            ->get();

        foreach ($quiet as $agent) {
            $agent->forceFill(['offline_reported_at' => $now])->save();
            $events->record($agent, 'agent.offline', 'warning',
                $agent->name.' stopped checking in (last seen '.$agent->last_seen_at?->diffForHumans($now, short: true).')',
                ['last_seen_at' => $agent->last_seen_at?->toIso8601ZuluString()]);
        }
        if ($quiet->isNotEmpty()) {
            $this->info("{$quiet->count()} computer(s) went offline.");
        }

        return self::SUCCESS;
    }
}
