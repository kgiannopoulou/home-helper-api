# Scheduled jobs and push

On the phone, the list filled itself only if someone opened the app the day before shopping day. Here the work runs on Laravel's scheduler and a Redis queue, so it happens on time with every phone closed, and a push tells the household.

| Job (`app/Jobs`) | When (at home) | What it does |
|---|---|---|
| `ApplyRecurringBills` | daily 06:00 | Adds each active bill as an expense once its day of the month has come. A missed day is caught up later in the month; past months are never backfilled. A deleted bill expense stays deleted. |
| `LearnChoreFrequencies` | daily 04:00 | Chores done 30% late (or early) 3 times running move one step along the frequencies (the `slipping-chores` query). The old frequency is kept, so the phone can offer Undo. |
| `PrepareShoppingList` | daily 17:00 | The day before the usual shopping day (`shopping-day`), adds what's low or empty and what runs out before the shop after it (`run-out`), skipping what's already listed (same name matching as the phone). Push: *"🛒 Tomorrow is Saturday shopping · Added Eggs, Milk and Dish soap to the list."* |
| `BudgetAlert` | daily 18:00 | When `budget-forecast` goes over the monthly budget, a push: *"At this pace: €1,395 of €1,000 this month, €395 over."* Once a month, and again only if the overshoot grows by another 10% of the budget. |
| `WeeklySummary` | Sunday 19:00 | An email (a Markdown `Mailable`) and a push to each member: spending this week, the month's pace, overdue chores and chores that changed frequency, and **their own** protein, fibre and water gaps (`NutritionGapsQuery`). Nobody sees another member's food. |

```php
// routes/console.php
Schedule::job(new PrepareShoppingList)->dailyAt('17:00')->timezone($home)->onOneServer();
```

- **One job per household.** The scheduled run only fans out: it queues one copy per household, all for the same date (`HouseholdJob`), so a run that starts at 23:59 and ends after midnight keeps its day, households run side by side on the workers, and one failing doesn't stop the rest. Each failed one is retried (3 tries, back off 1 then 5 minutes).
- **Home time.** `HOME_TIMEZONE` sets both when jobs run and what "today" is. The queries take today as a binding (Phase 3), so they don't depend on the server's clock.
- **Safe to run twice.** Bills are added once per month, the list skips what's on it (so a second run adds nothing and sends no push), and a learned chore isn't touched again for 3 cycles.
- **`job_runs`** logs every run: household, job, status (running, succeeded, failed), the day it ran for, the attempt, duration in ms, the error, and a JSON summary of what it did (the items added and why, the forecast, how many phones the push reached). `BudgetAlert` reads its own earlier runs from it to decide whether to alert again.
- **Push** goes through the Expo Push API (`app/Push/ExpoPush.php`), 100 messages per request. A phone that uninstalled the app answers `DeviceNotRegistered`, and its token is deleted. If Expo is down, the push is counted as failed but the job still succeeds, because its real work (the list, the bill) is already saved.
- **Devices** belong to the Sanctum token the phone logged in with (`devices.personal_access_token_id`, `ON DELETE CASCADE`), so logging out stops that phone's pushes without any extra code.

Run any job now, for every household:

```bash
php artisan household:run PrepareShoppingList --date=2026-10-09   # as if today were Friday 9 October
php artisan queue:work --stop-when-empty
```

Tests (`tests/Feature/Jobs/HouseholdJobsTest.php`) use `Queue::fake()` for the fan-out, `Mail::fake()` for the summary and `Http::fake()` for Expo: the schedule itself, the job_runs log (success and failure), the list filled the evening before and not on other days, running twice, bills once per month with catch-up and no backfill, budget alerts only when it gets worse, learned chores, each member's private nutrition gaps in the rendered email, forgotten phones and Expo being down.

