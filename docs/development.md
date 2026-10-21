# Development: run it and test it

## With Sail (Linux, macOS, WSL 2)

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate   # then set DB_HOST=mysql and REDIS_HOST=redis
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail npm run dev          # http://localhost
```

## On Windows without WSL

PHP 8.4 and Composer run natively, and Docker runs only the databases:

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
docker compose up -d mysql redis       # .env.example already points at 127.0.0.1
php artisan migrate:fresh --seed
composer run dev                       # http://localhost:8000
```

The scheduled jobs need a queue worker and the scheduler (in two more terminals):

```bash
php artisan queue:work                 # or ./vendor/bin/sail artisan queue:work
php artisan schedule:work              # on a server, one cron line instead: * * * * * php artisan schedule:run
```

Set `HOME_TIMEZONE` (e.g. `Europe/Athens`) so "17:00" means 17:00 at home. On Windows without the phpredis extension, `REDIS_CLIENT=predis` (the default in `.env.example`) talks to Redis in plain PHP.

To use it from a phone, set `APP_URL` to an address the phone can reach (for example `http://192.168.1.20:8000`, with `php artisan serve --host=0.0.0.0`), because invite links are built from it. With `MAIL_MAILER=log`, invite emails go to `storage/logs/laravel.log`.

## Tests

```bash
php artisan test                       # or ./vendor/bin/sail test
npm test                               # Vitest + React Testing Library
```

[GitHub Actions](../.github/workflows/tests.yml) runs them on every push, against a MySQL 8.4 service: `composer install`, `php artisan migrate`, Pint, the production build, Pest, `tsc` and Vitest.

On Windows with Docker Desktop, PHP talking to MySQL through the forwarded port sometimes stalls during `migrate:fresh`: MySQL has answered, but PHP never gets the reply. Running PHP in a container on the same Docker network avoids that:

```bash
docker run --rm --network home-helper-api_sail -v "$PWD:/app" -w /app -e DB_HOST=mysql   php:8.4-cli sh -c "docker-php-ext-install pdo_mysql >/dev/null && php vendor/bin/pest"
```

The tests run against the real MySQL `testing` database (Sail creates it), so the foreign keys, CHECK constraints and generated columns are tested too. `tests/Feature/Database/SchemaTest.php` covers cascades, constraints, unique keys with soft deletes, and the demo seeder's story. `tests/Feature/Insights` checks the exact numbers of every insight query, using the same cases as the app's Jest tests.

The API tests (`tests/Feature/Api`) run the same checks on every module: list, create, invalid input, update, delete, and that another household's rows are refused (403 for the household URL, 404 for a row under yours). Another test makes sure a real token from another household is refused too. Separate tests cover login, logout and rate limiting, households and signed invites (tampered, expired, wrong person, used twice), date filters and pagination, private personal logs, cross-household ids, 409 conflicts and UTC times.

Cross-cutting API tests:

- `AuthorizationTest` sweeps every route in `routes/api.php`: each needs a token, each household route refuses a non-member, and the list of public routes is pinned.
- `ErrorsTest` checks the error body (`message`, `code`, `errors`, `request_id`) for 401/403/404/405/409/422, and the `X-Request-Id` round trip.
- `RateLimitTest` covers the per-user API limit with `Retry-After`, sync, invites, and login per account and per address.
- `ListConventionsTest` covers `sort` (allowlisted columns only), `updated_since`, stable paging on ties and the envelope.
- `OpenApiTest` and `Database/SchemaDiagramTest` fail when `docs/openapi.json` or `docs/database.md` no longer match the code.

## Docs generated from the code

```bash
composer docs:api                      # docs/openapi.json (Scramble); browse it live at /docs/api
php artisan docs:schema                # docs/database.md (Mermaid ER diagrams from information_schema)
```

Both need a migrated database: Scramble reads the models' columns, and the diagrams are drawn from the live schema.

Check the keys yourself:

```sql
SHOW CREATE TABLE expenses;
```
