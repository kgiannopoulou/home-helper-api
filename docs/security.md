# Security

Days 4 and 11 of [Phase 1](phase-1.md): authentication, authorization, Sanctum, household permissions, rate limits and HTTP protections. What was reviewed, what changed, and what is still open.

## Authentication

| | Web dashboard | Phone API |
|---|---|---|
| How | Session cookie (Fortify) | Sanctum bearer token (`POST /api/login`) |
| Password rules | 12+ characters, mixed case, numbers, symbols and not in a known breach, in production (`AppServiceProvider::configureDefaults`) | Same accounts |
| Second factor | TOTP two-factor and passkeys | Not on the token login yet (see gaps) |
| Email verification | Required for every page (`verified`) | Not required for the token login (see gaps) |
| Brute force | 5 / min per email + IP | 6 / min per email + IP, and 30 / min per IP |
| Expiry | `SESSION_LIFETIME` (120 min) | **90 days** (`SANCTUM_TOKEN_EXPIRATION`), then the phone asks to log in again. Expired rows are pruned daily |
| Logout | Ends the session | Deletes that token, and its push device (cascade) |

**Changed in Phase 1:**

- **Tokens expire.** They didn't (`expiration => null`). A token copied off a phone would have worked forever.
- **Login timing doesn't reveal accounts.** For an unknown email the old code skipped the bcrypt check and answered faster, so timing told which emails had accounts. It now hashes either way, and the answer (`auth.failed` on `email`) is identical. Tested in `tests/Feature/Ops/SecurityTest.php`.

## Authorization: who may do what

```
not signed in ─────────────▶ 401
signed in, not a member ───▶ 403 on every /households/{h}/… URL      (can:view,household)
member ────────────────────▶ shared data: read and write              (HouseholdDataPolicy)
                              personal logs: only your own             (PersonalDataPolicy)
                              another household's row id: 404          (looked up through the household)
owner ─────────────────────▶ the above, plus rename, location, invite (HouseholdPolicy)
admin (OPS_ADMIN_EMAILS) ──▶ /system/health and health details        (Gate viewSystemHealth)
```

`household_id` and `user_id` are never read from a request body. `tests/Feature/Api/AuthorizationTest.php` sweeps every API route: each one needs a token, each household route refuses a non-member, and the list of public routes (`login`, `health`) is pinned, so a new route can't ship open by accident. Below the application, composite foreign keys now stop cross-household links in the database too ([architecture-review.md](architecture-review.md)).

**Sanctum guard.** `sanctum.guard` is `web`, so a browser on the same domain could call the API with its session cookie (Sanctum's SPA mode, with CSRF). The dashboard doesn't use the API this way; leave `SANCTUM_STATEFUL_DOMAINS` at its default.

## Rate limits

Every limiter and its key is in [api.md](api.md#rate-limits). Reviewed in Phase 1:

- Authenticated limits count **per user**, so a household behind one router doesn't share a budget.
- Login counts per email + IP **and** per IP, so one address can't try many accounts quickly.
- **New:** `GET /api/health` (public, touches the database) is limited to 30 / min per IP.
- **Behind a proxy**, every request comes from the proxy's IP unless `TRUSTED_PROXIES` is set; then one user could lock everyone out of login. `config/trustedproxy.php` reads it; leave it empty when the app faces the internet directly, so nobody can choose their own IP with `X-Forwarded-For`.

## HTTP protections

`app/Http/Middleware/SecurityHeaders.php`, on every response:

| Header | API | Web |
|---|---|---|
| `X-Content-Type-Options: nosniff` | ✅ | ✅ |
| `Referrer-Policy: strict-origin-when-cross-origin` | ✅ | ✅ |
| `Permissions-Policy` (no camera, mic, location, payment) | ✅ | ✅ |
| `Cross-Origin-Opener-Policy: same-origin` | ✅ | ✅ |
| `X-Frame-Options` | `DENY` | `SAMEORIGIN` |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | none (Inertia and the Vite dev server need inline scripts; a nonce-based CSP is a gap) |
| `Cache-Control: no-store, private` | ✅ personal data | |
| `Strict-Transport-Security` | over HTTPS in production | over HTTPS in production |

**CORS** (`config/cors.php`): `CORS_ALLOWED_ORIGINS`, `*` locally and the site's own origin in production. Only the methods and headers the API uses; `X-Request-Id` and `Retry-After` are exposed so the phone's web build can read them; no credentials, because tokens travel in the `Authorization` header.

**Errors** never show internals in production: a 500 is a fixed message, a code and the request id ([api.md](api.md#errors)). `app:production-check` fails if `APP_DEBUG` is on.

**Secrets**: the database password goes to `mysqldump` through `MYSQL_PWD`, not the command line where `ps` shows it. Slow-query logs leave the bindings out, because they can be personal data. `.env.production.example` contains no working secrets, and the demo seeder refuses to run in production ([deployment.md](deployment.md)).

**Dependencies**: CI runs `composer audit` and `npm audit` (production, high and above) on every push. Phase 1 started with advisories in `symfony/http-foundation`, `http-kernel`, `mime`, `routing`, `serializer`, `yaml` and in `vite`/`shell-quote`; they were updated to fixed patch releases. Dependabot opens grouped weekly updates.

## Gaps

Known and not fixed in Phase 1, roughly in order of importance:

1. **Token login skips two-factor and email verification.** A password alone gets a 90-day token. Fix: require the TOTP code on `POST /api/login` when 2FA is on, and refuse unverified emails.
2. **No way to remove a member, leave a household or transfer ownership.** A member who moves out keeps access until the database is edited.
3. **No "log out other devices".** Tokens can't be listed or revoked from the dashboard.
4. **No CSP on the web pages.** Needs Vite's nonce support.
5. **Single owner.** If the owner's account is deleted, nobody can invite.
