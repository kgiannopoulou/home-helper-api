# Deployment

Day 5 of [Phase 1](phase-1.md): production configuration without demo credentials. The app isn't deployed anywhere yet; this is what a deploy needs.

## Two environment files

| File | For | Contains |
|---|---|---|
| `.env.example` | Local development | The Sail password `password`, `APP_DEBUG=true`, mail to the log file, the demo owner as health admin |
| `.env.production.example` | A real server | Blanks for every secret, `APP_DEBUG=false`, HTTPS, JSON logs at `info`, Redis for cache, sessions and queues, encrypted secure session cookies, token expiry, a CORS allowlist, off-server backups |

The demo data can't reach production:

- `DemoSeeder` throws in production. It creates `demo@homehelper.test` and `alex@homehelper.test` with the password `password`.
- `php artisan db:seed` in production seeds nothing.
- `app:production-check` fails while any `@homehelper.test` account exists.

## `php artisan app:production-check`

Run it on the server before switching traffic. It exits 1 on any **FAIL**; **warn** lines are worth a look but may be fine on purpose.

| Check | Fails when |
|---|---|
| APP_DEBUG is off | debug pages would show code, queries and environment values |
| APP_KEY is set | |
| APP_URL uses https | invite links and secure cookies need it |
| Database password is not a default | empty, `password`, `secret`, `root`, `sail` |
| Database user is not root | |
| No demo logins in the database | |
| Mail is really sent | `MAIL_MAILER` is `log` or `array` |
| Queue is not sync | |
| Cache persists | `CACHE_STORE=array` |
| Session cookie is HTTPS-only | |
| API tokens expire | `SANCTUM_TOKEN_EXPIRATION` empty |
| (warn) APP_ENV, LOG_LEVEL, CORS, OPS_ADMIN_EMAILS, OPS_ALERT_EMAIL, BACKUP_DISK, config cache | |

Run against the local development setup, it fails as it should:

```
| warn | APP_ENV is production              | is local                                               |
| FAIL | APP_DEBUG is off                   | debug pages show code, queries and environment values  |
| ok   | APP_KEY is set                     |                                                        |
| FAIL | APP_URL uses https                 | is http://localhost:8000                               |
| FAIL | Database password is not a default | set a long random DB_PASSWORD                          |
| FAIL | Database user is not root          | use a user with rights on this database only           |
| FAIL | No demo logins in the database     | 2 account(s) with the password "password": delete them |
| FAIL | Mail is really sent                | MAIL_MAILER is log: invites would never arrive         |
| ok   | Queue is not sync                  |                                                        |
| ok   | Cache persists                     |                                                        |
| FAIL | Session cookie is HTTPS-only       | SESSION_SECURE_COOKIE=true                             |
...
7 failed, 7 warnings. Not safe for production.
```

## A deploy, step by step

```bash
git fetch --tags && git checkout v1.2.0
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force            # DB::prohibitDestructiveCommands() blocks migrate:fresh in production
php artisan optimize                   # config, routes, events and views cached
php artisan app:production-check       # stop here if it fails
php artisan queue:restart              # workers finish their job and reload the new code
```

Once per server:

- **Workers:** `ops/deploy/home-helper-worker.conf` for Supervisor (two workers, `--queue=default,households`, `stopwaitsecs` longer than the longest job).
- **Scheduler:** `ops/deploy/crontab`, one line. Everything it runs is in `routes/console.php` (`php artisan schedule:list`).
- **Backups:** `BACKUP_DISK=s3` and `composer require league/flysystem-aws-s3-v3`, plus `mysqldump` and `mysql` on the server ([backups.md](backups.md)).
- **Monitoring:** point an uptime monitor at `GET /api/health` (503 = down), set `OPS_ALERT_EMAIL` and `OPS_ADMIN_EMAILS` ([operations.md](operations.md)).
- **PHP:** `expose_php = Off`, so responses don't advertise the PHP version.
