<?php

use App\Jobs\ApplyRecurringBills;
use App\Jobs\BudgetAlert;
use App\Jobs\CheckPlantCare;
use App\Jobs\DailyBriefing;
use App\Jobs\HouseholdJob;
use App\Jobs\LearnChoreFrequencies;
use App\Jobs\MonthlyDigest;
use App\Jobs\PrepareShoppingList;
use App\Jobs\PruneVoiceConversations;
use App\Jobs\RememberConversations;
use App\Jobs\WeeklyDigest;
use App\Jobs\WeeklySummary;
use App\Models\AgentEvent;
use App\Models\AgentMetric;
use App\Ops\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Household jobs (Phase 5). Each one fans out to one queued job per household
| (app/Jobs/HouseholdJob.php). Times are at home (HOME_TIMEZONE), not the server's.
| Locally: `php artisan schedule:work` and `php artisan queue:work`.
| On a server: one cron line, `* * * * * php artisan schedule:run`.
*/
$home = config('homehelper.timezone');

Schedule::job(new ApplyRecurringBills)->dailyAt('06:00')->timezone($home)->onOneServer();
Schedule::job(new LearnChoreFrequencies)->dailyAt('04:00')->timezone($home)->onOneServer();
Schedule::job(new PrepareShoppingList)->dailyAt('17:00')->timezone($home)->onOneServer();
Schedule::job(new BudgetAlert)->dailyAt('18:00')->timezone($home)->onOneServer();
Schedule::job(new WeeklySummary)->sundays()->at('19:00')->timezone($home)->onOneServer();
// One evaluator for every plant, not one schedule per plant (app/Jobs/CheckPlantCare.php)
Schedule::job(new CheckPlantCare)->hourly()->timezone($home)->onOneServer();

/*
| Memory and household intelligence (Phase 3, docs/memory.md, docs/briefings.md).
*/
// Each member's morning briefing, pushed to their phones
Schedule::job(new DailyBriefing)->dailyAt(config('homehelper.briefing.time'))->timezone($home)->onOneServer();
// Last week and last month, written into long-term memory
Schedule::job(new WeeklyDigest)->mondays()->at('05:00')->timezone($home)->onOneServer();
Schedule::job(new MonthlyDigest)->monthlyOn(1, '05:30')->timezone($home)->onOneServer();
// Voice conversations past each person's chosen retention (docs/voice.md#privacy)
Schedule::job(new PruneVoiceConversations)->dailyAt('03:45')->timezone($home)->onOneServer();
// Conversations that went quiet, summarised into long-term memory
Schedule::job(new RememberConversations)->hourlyAt(15)->onOneServer();

/*
| Keeping it running (docs/operations.md). Times are at home too.
*/
// The health page counts the scheduler as stopped when this is older than 5 minutes
Schedule::call(fn () => Cache::forever(SystemHealth::SCHEDULER_HEARTBEAT, now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat')->onOneServer();

// QueueBusy (→ an ops alert) when a queue holds more than QUEUE_MAX_WAITING jobs
Schedule::command('queue:monitor', [
    implode(',', array_map(fn (string $q) => "redis:{$q}", (array) config('homehelper.queues.monitored'))),
    '--max='.config('homehelper.queues.max_waiting'),
])->everyMinute()->onOneServer();

// Backups: every night, and a restore into a scratch database every Sunday after it.
// Both raise an ops alert themselves when they fail.
Schedule::command('db:backup')->dailyAt('03:00')->timezone($home)->onOneServer();
Schedule::command('db:restore-test')->sundays()->at('03:30')->timezone($home)->onOneServer();

// Computers that stopped checking in (agent.offline, pushed to the phones)
Schedule::command('agents:check-offline')->everyMinute()->onOneServer()->withoutOverlapping();

// Devices and states from each household's Home Assistant: new and removed
// devices, and the fallback when a pushed state change was lost (docs/smart-home.md)
Schedule::command('smart-home:sync')->everyMinute()->onOneServer()->withoutOverlapping();

// Housekeeping
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily()->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->daily()->onOneServer();
Schedule::command('model:prune', ['--model' => [AgentMetric::class, AgentEvent::class]])->dailyAt('04:30')->timezone($home)->onOneServer();

// Run one now, for every household: php artisan household:run PrepareShoppingList --date=2026-10-09
Artisan::command('household:run {job} {--date= : "today" at home, YYYY-MM-DD}', function (string $job) {
    /** @var class-string<HouseholdJob> $class */
    $class = 'App\\Jobs\\'.$job;
    if (! is_subclass_of($class, HouseholdJob::class)) {
        $this->error("No household job called {$job}.");

        return 1;
    }
    dispatch(new $class(null, $this->option('date')));
    $this->info("Queued {$job} for every household. Run `php artisan queue:work` if no worker is running.");

    return 0;
})->purpose('Queue a household job now, for every household');
