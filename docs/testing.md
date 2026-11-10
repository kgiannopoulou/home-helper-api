# Test baseline

Day 14 of [Phase 1](phase-1.md): the complete suite, run before and after Phase 1, so later phases have numbers to compare against. How to run the tests: [development.md](development.md#tests).

## The numbers

Measured 2026-11-10 on the same machine, both runs in a Docker container on the Sail network against MySQL 8.4 (the setup in [development.md](development.md)).

| | Before Phase 1 (commit `4413cdf`) | After Phase 1 |
|---|---:|---:|
| Pest tests | 359 | **407** |
| Assertions | 2,382 | **2,612** |
| Failures | 0 | 0 |
| Pest duration | 173 s | 196 s |
| Vitest tests | 14 | **18** |
| PHPStan level 7 | not in CI, 285 errors | 0 new errors (285 in the baseline) |
| `composer audit` | 11 advisories (Symfony) | 0 |
| `npm audit --omit=dev` | 3 (1 high, 2 critical) | 0 |

The 23 extra seconds are the backup tests: they run the real `mysqldump` and `mysql` three times.

### Pest tests per area

| Area | Before | After | Covers |
|---|---:|---:|---|
| `Feature/Api` | 183 | 183 | Every module's CRUD, the authorization sweep, errors, rate limits, sync, households, invites |
| `Feature/Plants` | 55 | 55 | Plant care rules, weather alerts, the hourly job |
| `Feature/Auth` | 26 | 26 | Fortify: login, registration, reset, verification, 2FA |
| `Feature/Web` | 25 | 25 | Inertia pages and the week planner |
| `Feature/Insights` | 22 | 22 | Exact numbers of every SQL insight |
| `Feature/Jobs` | 20 | 20 | The household jobs and push |
| `Feature/Database` | 13 | **19** | Schema constraints, the demo seeder, ER diagrams; **new:** same-household foreign keys, new relationships |
| `Feature/Settings` | 10 | 10 | Profile, password, 2FA settings |
| `Feature` (root) | 4 | 4 | Dashboard, example |
| `Unit` | 1 | 1 | |
| `Feature/Ops` | – | **42** | **New:** health endpoint and page, alerts and cooldown, backup and restore with real clients, production check, security headers, CORS, token expiry, login timing |
| **Total** | **359** | **407** | |

### Slowest tests

| Time | Test | Why |
|---:|---|---|
| ~20 s | `Unit: that true is true` | The first test of the run pays for loading the code over the Docker bind mount from Windows; on Linux CI it's under a second |
| 4.0 s | the demo seeder builds one household with six months of data | Seeds the whole demo |
| 3.2 s | new users can register | bcrypt + Fortify pipeline |
| 3.1 s | every insight endpoint answers for the demo household | Seeds the demo, then runs all five queries |

## What the suite doesn't cover

- **The phone app** (its own repository and tests) and a real Expo push to a real device.
- **A real mail server**: mail is faked or logged.
- **Load**: no performance tests; the `EXPLAIN ANALYZE` work in [insights.md](insights.md) is the closest.
- **Code coverage**: CI runs without a coverage driver. Every route is reached by the authorization sweep, but line coverage isn't measured.
- **Off-server backup disks**: the backup tests use the local disk.

## Flakiness

None seen in the runs for this baseline. On Windows, PHP talking to MySQL through Docker Desktop's forwarded port can stall during `migrate:fresh`; running PHP in a container on the same network avoids it ([development.md](development.md#tests)). This is an environment problem, not a test problem.
