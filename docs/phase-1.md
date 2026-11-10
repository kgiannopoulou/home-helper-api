# Phase 1: Foundation and architecture

**Goal:** make the existing project production-ready, so later phases build on something reliable.
**Deliverable:** a foundation that can safely be built on: documented, protected, observable, backed up, and measured.
**Done:** 2026-11-10.

| Day | Task | Result |
|---:|---|---|
| 1 | Review the repository; document the architecture and features | [architecture.md](architecture.md): layers, request flows, running processes, diagram |
| 2 | Complete feature checklist | [feature-checklist.md](feature-checklist.md): every feature marked works / partial / missing |
| 3 | Missing relationships and architectural problems | [architecture-review.md](architecture-review.md). **Fixed:** composite foreign keys so child rows can't point into another household (they caught inconsistent test data straight away), 8 missing Eloquent relationships, a queue setting that could run a job twice, one queue for everything, invisible failures, tokens that never expired, PHPStan not in CI |
| 4 | Authentication, authorization, Sanctum, household permissions | [security.md](security.md). **Fixed:** tokens expire after 90 days and are pruned; login no longer reveals which emails exist by its timing. Gaps listed (2FA on token login, removing members) |
| 5 | Production environment; no demo credentials in production | `.env.production.example`, `php artisan app:production-check` (fails on debug, default DB password, demo logins, mail to log…), demo seeder refuses production. [deployment.md](deployment.md) |
| 6 | Automated backups, and test a restore | `db:backup` nightly, `db:restore-test` weekly into a scratch database with row counts checked; drill run for real and in CI. [backups.md](backups.md) |
| 7 | Rest and review | The review of days 1–6 is the "Fixed / left as they are" split in [architecture-review.md](architecture-review.md) and the gaps in [security.md](security.md) |
| 8 | Protect `main`, pull-request checks | Branch protection with three required checks (`tests`, `static-analysis`, `dependency-audit`), PR template, Dependabot for Composer and npm. [contributing.md](contributing.md) |
| 9 | Logging and exception reporting | JSON log channel; request id, user and household on every line (also inside queued jobs); exception context; slow-query log; ops alerts by email with a cooldown. [operations.md](operations.md#logs) |
| 10 | Redis queues and failed-job monitoring | `retry_after` fixed, own `households` queue, `after_commit`, `queue:monitor` every minute, alerts on failed jobs, failed-job pruning, Supervisor config. [operations.md](operations.md#queues) |
| 11 | Rate limits and security protections | Health endpoint limit, trusted proxies, security headers (CSP on the API), CORS allowlist, dependency audits in CI, vulnerable Symfony and npm packages updated. [security.md](security.md) |
| 12 | API documentation and error responses | 500s never leak the exception message, new `413`/`415`/`503` codes, full rate-limit table, health endpoint documented, OpenAPI regenerated. [api.md](api.md#errors) |
| 13 | System health dashboard | `GET /api/health` for uptime monitors and `/system/health` for admins: database, Redis, queues, failed jobs, scheduler, household jobs, backups, disk. [operations.md](operations.md#health) |
| 14 | Full test suite and baseline | 359 → **407** Pest tests, 14 → **18** Vitest, 0 failures. [testing.md](testing.md) |

## What changed, in numbers

- 48 new Pest tests and 4 new Vitest tests; nothing removed.
- 1 migration (5 composite foreign keys, 4 unique keys).
- 3 new artisan commands: `db:backup`, `db:restore-test`, `app:production-check`.
- 2 new endpoints: `GET /api/health`, `/system/health`.
- 3 CI jobs instead of 1, all required to merge into `main`.
- 0 known vulnerabilities in shipped dependencies (from 14).

## Open items carried into later phases

From [security.md](security.md#gaps) and [feature-checklist.md](feature-checklist.md): two-factor on the token login, removing members and transferring ownership, revoking other devices, a CSP for the web pages, an off-server backup disk configured on a real server, and an actual deployment.
