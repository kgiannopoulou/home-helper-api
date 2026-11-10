# Contributing: branches, pull requests and checks

Day 8 of [Phase 1](phase-1.md). `main` is what would be deployed, so nothing reaches it without passing the checks.

## `main` is protected

Set in the repository's branch protection (Settings → Branches), since 2026-11-10:

| Rule | Setting |
|---|---|
| Changes go through a pull request | Yes. No approving review required: this is a one-person repository, and GitHub doesn't let you approve your own pull request |
| Required status checks | `tests`, `static-analysis`, `dependency-audit` |
| Branch must be up to date before merging | Yes, so the checks ran against what `main` will actually become |
| Conversations resolved before merging | Yes |
| Linear history | Yes (squash or rebase merges) |
| Force pushes and deleting `main` | Blocked |
| Rules apply to admins too | No: the owner can still push a fix directly in an emergency. That is the one deliberate escape hatch |

## The checks (`.github/workflows/tests.yml`)

Run on every push to `main` and every pull request; a newer push cancels the older run.

| Job | What it does | Fails when |
|---|---|---|
| `tests` | MySQL 8.4 and Redis services, migrate, Pint, build the dashboard, Pest (407 tests), the backup and restore drill, generated docs up to date, `tsc`, Vitest | Any test fails, code style is off, `docs/openapi.json` or `docs/database.md` are stale |
| `static-analysis` | PHPStan level 7 (Larastan) | Any error that isn't in `phpstan-baseline.neon` |
| `dependency-audit` | `composer audit`, `npm audit --omit=dev --audit-level=high` | A known vulnerability in a package that ships |

Dependabot opens grouped weekly pull requests for Composer, npm and GitHub Actions; they go through the same checks.

## Working on a change

```bash
git switch -c feature/remove-member
# ...code, tests...
vendor/bin/pint && php artisan test && npm test
git push -u origin feature/remove-member
gh pr create --fill          # the template asks for what, why and how it was tested
```

Merge with **Squash and merge** once the three checks are green. The pull request template (`.github/pull_request_template.md`) has the checklist: migrations with `down()`, new API routes behind `auth:sanctum`, regenerated OpenAPI and ER docs, new env vars in both `.env` templates.

## The PHPStan baseline

`phpstan-baseline.neon` lists the 285 errors that were already in the code when PHPStan was added to CI. New code must not add to it. To pay some of it off: fix errors in a file, then

```bash
vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
```

and commit the smaller baseline.
