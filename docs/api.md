# API reference

JSON over HTTPS with a Sanctum bearer token. Every module lives under one household, and the API only ever returns rows of households you belong to.

- **OpenAPI 3.1 document:** [`openapi.json`](openapi.json). It is generated from the routes, Form Requests and Resources by [Scramble](https://scramble.dedoc.co) (`composer docs:api`), and CI fails if it is out of date. Import it into Postman, Insomnia or any Swagger viewer.
- **Interactive docs:** run the app locally and open http://localhost:8000/docs/api. It has every endpoint, its request schema and responses, and a "Try it" button.

## Endpoints

```http
POST /api/login            {email, password, device_name}  → {token, user}   token lasts 90 days
GET  /api/health                                            for uptime monitors, see operations.md
POST /api/logout                                            revokes this token
GET  /api/me                                                you + your households
POST   /api/devices        {token, platform, name}          this phone's Expo push token
DELETE /api/devices        {token}                          no more pushes to it (logout does this too)

GET  /api/households                                        yours, with your role
POST /api/households       {name, currency}                 you become the owner
GET  /api/households/{household}
GET  /api/households/{household}/members
POST /api/households/{household}/invites   {email}          owner only; emails a signed link
POST /api/invites/{invite}/accept?expires=…&signature=…     the link from the email

GET|POST             /api/households/{household}/{module}
GET|PUT|PATCH|DELETE /api/households/{household}/{module}/{id}

POST /api/households/{household}/sync                       phone sync, see sync.md
GET  /api/households/{household}/insights/{weekly-spending|run-out|slipping-chores|shopping-day|budget-forecast}

PATCH /api/households/{household}          {name, currency, latitude, longitude}   owner only
POST /api/households/{household}/plants/{plant}/{water|fertilize|repot|prune|mist|move|inspect}
GET  /api/households/{household}/plants/{plant}/care-history
GET  /api/households/{household}/plant-alerts               POST …/plant-alerts/{id}/resolve
GET  /api/households/{household}/plant-care/{today|plan}    see plants.md

GET|POST /api/households/{household}/assistant/conversations          the assistant, see assistant.md
POST     /api/households/{household}/assistant/conversations/{id}/messages
POST     /api/households/{household}/assistant/actions/{id}/{confirm|decline}
GET|POST /api/households/{household}/memory/facts                     facts and preferences, see memory.md
GET|POST /api/households/{household}/memory/notes                     notes; multipart `file` for a document
GET      /api/households/{household}/memory/search?q=                 hybrid search over notes, facts, summaries
GET      /api/households/{household}/timeline                         what happened, from every module
GET      /api/households/{household}/summaries                        weekly and monthly summaries
GET      /api/households/{household}/briefing                         today's briefing, see briefings.md
GET|POST /api/households/{household}/recommendations[/feedback]
```

**Modules:** `expenses`, `recurring-bills`, `budgets`, `inventory-items`, `purchases`, `shopping-items`, `shopping-trips`, `item-prices`, `rooms`, `chores`, `chore-completions`, `supplies`, `food-entries`, `water-entries`, `sleep-entries`, `workouts`, `weights`, `events`, `todos`, `admin-items`, `plants`, `plant-care-logs`.

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/login -H 'Accept: application/json' \
  -d email=demo@homehelper.test -d password=password | jq -r .token)
curl -s localhost:8000/api/households -H "Authorization: Bearer $TOKEN" | jq
curl -s "localhost:8000/api/households/{id}/expenses?from=2026-07-01&to=2026-07-31&sort=-amount" \
  -H "Authorization: Bearer $TOKEN" | jq
```

## Lists

Every module's `GET …/{module}` takes the same parameters:

| Parameter | Example | Notes |
|---|---|---|
| `page` | `2` | Starts at 1 |
| `per_page` | `50` | 1–100, default 25 |
| `sort` | `-amount` | A column name for ascending, `-column` for descending. Sortable: the module's date column, `created_at`, `updated_at`, plus `name` or `amount` where it makes sense. Default: newest first by the date column, or by name |
| `from`, `to` | `2026-09-01` | Dated modules only. Both days included, even for timestamp columns |
| `updated_since` | `2026-10-01T08:00:00+03:00` | Rows changed at or after this time |

Results always come in Laravel's paginated envelope. Rows with the same sort value keep a stable order across pages (`id` breaks ties).

```json
{
  "data": [{ "id": "01J…", "date": "2026-09-30", "amount": "54.30", "...": "..." }],
  "links": { "first": "…?page=1", "last": "…?page=3", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 25, "total": 61, "from": 1, "to": 25 }
}
```

## Errors

Every error has the same body, and the same `request_id` comes back in the `X-Request-Id` header. Send your own `X-Request-Id` and it is kept, so a phone's log line can be matched to the server's.

```json
{
  "message": "The amount field must be at least 0.01.",
  "code": "validation_failed",
  "errors": { "amount": ["The amount field must be at least 0.01."] },
  "request_id": "3f0c2a4e-8d4b-4b5e-9a57-1d2f6c1b9e10"
}
```

`message` and `errors` sit where Laravel always puts them, so existing clients keep working. Branch on `code`:

| Status | `code` | When |
|---|---|---|
| 401 | `unauthenticated` | No token, or it was revoked |
| 403 | `forbidden` | Not a member of that household, or not allowed (e.g. a member inviting) |
| 404 | `not_found` | No such row *in this household*. The message never names models or ids |
| 404 | `not_found` | Also for an API path that doesn't exist |
| 405 | `method_not_allowed` | The route doesn't take that method |
| 409 | `conflict` | A unique key clashed (the same item name twice) |
| 413 | `payload_too_large` | The body is bigger than PHP's `post_max_size` |
| 415 | `unsupported_media_type` | Not JSON or form data |
| 422 | `validation_failed` | Form Request rules; `errors` has the fields |
| 422 | `constraint_violation` | A CHECK constraint caught what validation missed (the safety net) |
| 429 | `too_many_requests` | Rate limited; wait for `Retry-After` seconds |
| 500 | `server_error` | Logged with the request id and the user, and raises an ops alert. With `APP_DEBUG=false` the body is only a fixed message, the code and the id: never the exception's message |
| 503 | `ai_unavailable` | The assistant's model can't answer (no key, provider down); `retryable` says whether to try again. The question is kept |
| 409 | `conversation_busy`, `action_closed` | The assistant is still answering; a proposed change was already confirmed or declined |
| 422 | `conversation_full` | 200 messages: start a new conversation |
| 429 | `daily_limit_reached` | The person's assistant messages for today are used up |
| 503 | `unavailable` | Maintenance mode (`php artisan down`), or `GET /api/health` when the database is unreachable |

A server error looks like this in production; quote the `request_id` when reporting it, and the log line is found with it:

```json
{
  "message": "Something went wrong on our side. It has been logged; quote the request_id if you report it.",
  "code": "server_error",
  "request_id": "3f0c2a4e-8d4b-4b5e-9a57-1d2f6c1b9e10"
}
```

`tests/Feature/Api/ErrorsTest.php` and `tests/Feature/Ops/AlertsTest.php` pin these bodies.

## Rate limits

| Limiter | Limit | Counted per |
|---|---|---|
| `api`: everything behind a token | 120 / minute | user |
| `api-login`: `POST /api/login` | 6 / minute, and 30 / minute per address | email + IP, and IP |
| `sync`: `POST …/sync` | 60 / minute | user |
| `invites`: `POST …/invites` | 20 / hour (each one sends an email) | user |
| `health`: `GET /api/health` | 30 / minute | IP |
| `ai`: sending to the assistant (API and page) | 10 / minute, plus 200 / day | user |
| `uploads`: adding a note or document (API and page) | 10 / minute | user |
| web: `POST household/invites` | 10 / minute | user |
| web: Fortify login | 5 / minute | email + IP |
| web: two-factor code | 5 / minute | login attempt |
| web: passkeys | 10 / minute | passkey (or session) + IP |

They are defined in `AppServiceProvider::configureRateLimits()`. Behind a proxy, set `TRUSTED_PROXIES` or every user shares the proxy's address ([security.md](security.md)).

## How access is enforced

- **Route:** `can:view,household`. If you aren't a member, every URL under that household is **403**. `tests/Feature/Api/AuthorizationTest.php` sweeps *every* API route: each needs a token (401), each household route refuses a non-member (403), and the list of public routes is pinned, so a new endpoint can't ship unprotected.
- **Query:** rows are always looked up through the household's relation, so another household's row under your household's URL is **404**. It is never loaded, so nothing about it can leak.
- **Policies:** one per model (`app/Policies`), sharing `HouseholdDataPolicy`. Food, water, sleep, workouts and weight use `PersonalDataPolicy`: other members of your household can't see them either.
- **Validation:** ids in a body (`recurring_bill_id`, `room_id`, `assignee_id`…) must belong to the same household, so nobody can link to another household's rows.
- **Ownership:** `household_id` and `user_id` are never taken from the body. They come from the URL and the token.
- **Database:** composite foreign keys make MySQL itself refuse a purchase, chore, completion, care log or plant alert that points at another household's row ([architecture-review.md](architecture-review.md)).
- **Roles:** only the owner can invite. Members can read and write shared data, and only their own personal logs.

## Conventions

- **One Form Request per module** (`app/Http/Requests/Api`), written as the rules for creating a row. `PATCH` turns `required` into `sometimes`, except for fields checked together, like bed and wake times.
- **Times** are ISO 8601 with an offset and are stored in UTC. Calendar days are plain `YYYY-MM-DD`.
- **Ids** are ULIDs (26 characters). Users keep integer ids.
- **Controllers** per module are a few lines each. Their shared behaviour (scoping, filters, sorting, pagination, policies) lives in `HouseholdDataController`.
