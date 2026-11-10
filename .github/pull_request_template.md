## What and why

<!-- One or two sentences: what changes, and the problem it solves. -->

## How it was tested

<!-- Tests added or changed, and anything checked by hand. -->

## Checklist

- [ ] `php artisan test` and `npm test` pass locally, or CI is green
- [ ] New code passes PHPStan without growing `phpstan-baseline.neon`
- [ ] A migration? It runs on a copy of real data and has a `down()`
- [ ] A new API route? It is in `routes/api.php` behind `auth:sanctum` (or added to `PUBLIC_API_ROUTES` on purpose), and `docs/openapi.json` is regenerated (`composer docs:api`)
- [ ] A schema change? `php artisan docs:schema` regenerated `docs/database.md`
- [ ] New environment variables are in `.env.example` and `.env.production.example`
