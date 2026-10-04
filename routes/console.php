<?php

use App\Jobs\ApplyRecurringBills;
use App\Jobs\BudgetAlert;
use App\Jobs\HouseholdJob;
use App\Jobs\LearnChoreFrequencies;
use App\Jobs\PrepareShoppingList;
use App\Jobs\WeeklySummary;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
