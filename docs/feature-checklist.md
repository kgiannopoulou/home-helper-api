# Feature checklist

Every feature the server has, and whether it works: ✅ works and is tested, 🟡 works but incomplete or untested in a real setup, ❌ not built. Checked on 2026-11-10 against the code and a full test run ([testing.md](testing.md)).

## Accounts and households

| Feature | Status | Where / notes |
|---|---|---|
| Register, log in, log out (web) | ✅ | Fortify, `tests/Feature/Auth` |
| Email verification, password reset | ✅ | Fortify; mail needs a real mailer in production |
| Two-factor authentication, passkeys | ✅ | Fortify; settings pages |
| Phone token login / logout / `me` | ✅ | `AuthController`, Sanctum; tokens expire after 90 days |
| Create a household, become owner | ✅ | API + web |
| Invite by email (signed link, expiry, single use) | ✅ | `InviteMember`, owner only |
| Switch household (web) | ✅ | `CurrentHousehold` |
| Rename household, set location | ✅ | Owner only |
| Remove a member, leave a household, transfer ownership | ❌ | No endpoint yet ([security.md](security.md#gaps)) |
| List and revoke my other devices' tokens | ❌ | Logout revokes only the current token |

## Household data (REST, 22 modules)

All with list (paging, sort, date filters, `updated_since`), show, create, update, delete and tests for another household's rows: ✅.

Money: expenses, recurring bills, budgets · Kitchen and shopping: inventory items, purchases, shopping items, shopping trips, item prices · Chores: rooms, chores, chore completions, supplies · Health (personal): food, water, sleep, workouts, weights · Planner: events, todos, admin items · Plants: plants, plant care logs.

| Feature | Status | Notes |
|---|---|---|
| Personal logs hidden from other members | ✅ | `PersonalDataPolicy` |
| Ids in a body must be in the same household | ✅ | Validation, and since Phase 1 composite foreign keys |
| Plant care actions (water, fertilize, repot…) | ✅ | `PlantCareController` |
| Plant alerts list and resolve | ✅ | |
| Plant care today / away plan | ✅ | `docs/plants.md` |
| Step counts | ✅ | Sync only (no REST module) |

## Sync

| Feature | Status | Notes |
|---|---|---|
| Two-way sync, 17 collections, LWW on `updated_at` | ✅ | `SyncService`, [sync.md](sync.md) |
| Tombstones, natural-key joins, renamed references | ✅ | |
| Server-clock cursor with overlap | ✅ | |
| Phone side | 🟡 | In the phone repo; push needs an EAS project id (not set up) |

## Insights (SQL)

| Insight | Status |
|---|---|
| Weekly spending by category, with change | ✅ |
| When staples run out | ✅ |
| Slipping chores | ✅ |
| Usual shopping day | ✅ |
| Budget forecast for the month | ✅ |
| Health weeks, fair share of chores, overdue chores, nutrition gaps | ✅ |

`EXPLAIN ANALYZE` before/after in `docs/explain`.

## Scheduled jobs and push

| Job | When | Status |
|---|---|---|
| ApplyRecurringBills | daily 06:00 | ✅ |
| LearnChoreFrequencies | daily 04:00 | ✅ |
| PrepareShoppingList | daily 17:00 | ✅ |
| BudgetAlert | daily 18:00 | ✅ |
| WeeklySummary (mail + push) | Sundays 19:00 | ✅ |
| CheckPlantCare (weather-aware) | hourly | ✅ |
| Expo push, dead tokens removed | | ✅ in tests; 🟡 never sent to a real phone (no EAS project id) |

## Web dashboard

| Page | Status |
|---|---|
| Dashboard (budget, this week, shopping list, overdue chores; polls every 10 s) | ✅ |
| Spending, Health, Chores (Recharts, "show as table") | ✅ |
| Week planner with apply | ✅ |
| Household (members, invite) | ✅ |
| Settings: profile, password, 2FA, passkeys, appearance | ✅ |
| System health (admins) | ✅ new in Phase 1 |
| Plant care card on the dashboard | ❌ ([plants.md](plants.md)) |

## Running it (Phase 1)

| Feature | Status | Notes |
|---|---|---|
| Production environment template, no demo credentials | ✅ | `.env.production.example`, `app:production-check` |
| Demo seeder blocked in production | ✅ | |
| Nightly backups, weekly restore test | ✅ | [backups.md](backups.md); off-server disk needs the S3 driver installed |
| JSON logs with request id, user and household | ✅ | [operations.md](operations.md) |
| Ops alerts by email (failed jobs, busy queues, server errors, backups) | ✅ | Needs `OPS_ALERT_EMAIL` and a mailer |
| Queue monitoring, failed-job pruning | ✅ | |
| Health endpoint and page | ✅ | |
| Security headers, CORS allowlist, trusted proxies | ✅ | [security.md](security.md) |
| Protected `main`, required checks | ✅ | [contributing.md](contributing.md) |
| Deployed anywhere | ❌ | Configs are ready (`ops/deploy`), no server yet |

## Oracle lab

| Feature | Status |
|---|---|
| Partitioning, archiving, RMAN, Data Pump, four recovery drills | ✅ ([oracle.md](oracle.md)) |
