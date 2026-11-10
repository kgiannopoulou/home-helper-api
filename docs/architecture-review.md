# Architecture review

Day 3 of [Phase 1](phase-1.md): missing database relationships and architectural problems. Each item says what was wrong, why it mattered, and whether it was fixed in Phase 1 or left with a reason.

## Fixed

### 1. Child rows could point at another household's parent

**Problem.** `purchases.inventory_item_id` was a plain foreign key to `inventory_items.id`. Validation (`inHousehold()`) stopped the API from linking a purchase to another household's item, but the database itself would accept it: a seeder, a bug in the sync service, or a manual `INSERT` could create a purchase in household A for an item in household B. The same held for chores → rooms, chore completions → chores, plant care logs → plants and plant alerts → plants.

**Fix.** Migration `2026_11_10_000001_add_same_household_foreign_keys` adds a unique key `(household_id, id)` on each parent and a composite foreign key on each child:

```sql
ALTER TABLE purchases ADD CONSTRAINT purchases_same_household_fk
  FOREIGN KEY (household_id, inventory_item_id) REFERENCES inventory_items (household_id, id) ON DELETE CASCADE;
```

**It caught something straight away:** two tests in `SchemaTest` built a chore with `Chore::factory()->for($home)`, which gave the chore one household and its room another. The tests passed before because nothing looked. They now build the room in the same household. `tests/Feature/Database/SameHouseholdKeysTest.php` proves each key refuses a cross-household row.

**Not covered, on purpose:** links that are `ON DELETE SET NULL` (expense → recurring bill, purchase/item price → shopping trip, plant → room, chore → assignee). A composite key with SET NULL would also null `household_id`, which is `NOT NULL`. Those stay protected by validation only.

### 2. Missing Eloquent relationships

The foreign keys existed, but some had no relationship method, so code had to query by hand:

| Added | Over |
|---|---|
| `User::memberships()`, `Household::memberships()`, `Membership::user()`, `Membership::household()` | `household_user` |
| `User::assignedChores()` | `chores.assignee_id` |
| `User::addedShoppingItems()` | `shopping_items.added_by` |
| `User::sentInvites()` | `invites.invited_by` |
| `Device::accessToken()` | `devices.personal_access_token_id` |

### 3. A job could run twice

`config/queue.php` had Redis `retry_after` at 90 seconds while `HouseholdJob::$timeout` is 120. Redis hands a job back to the queue after `retry_after`, so a job still running at 91 seconds would start a second time on another worker (two budget pushes, a bill added twice). `retry_after` is now 180, above every job's timeout. Also: `after_commit` is on (a job dispatched inside a transaction waits for the commit), `block_for` is 5 s (no busy polling of Redis).

### 4. One queue for everything

The household fan-out queues one job per household at the same moment. On a single queue, invite emails waited behind hundreds of household jobs. `HouseholdJob` now goes on its own `households` queue; workers take `default` first.

### 5. Failures nobody would see

A job that failed three times was only a row in `failed_jobs`; a server error only a log line. Now both raise an ops alert ([operations.md](operations.md)), and `/system/health` shows them.

### 6. Tokens that never expired

`sanctum.expiration` was `null`: a phone token stolen once worked forever. Now 90 days, with daily pruning. See [security.md](security.md).

### 7. Static analysis existed but didn't run

`phpstan.neon` (Larastan, level 7) was set up but not in CI, and the code had 285 errors. They are now in `phpstan-baseline.neon`, CI runs PHPStan on every push, and all Phase 1 code passes with no new baseline entries.

## Left as they are (with reasons)

| Finding | Why it stays for now |
|---|---|
| `User::roleIn()` runs a query each time. A policy check per row could become N+1 | Lists check `viewAny` once, not per row, and the household is checked once per request by `can:view,household`. Worth memoising if a page ever authorises many rows |
| `ItemPrice` and `InventoryItem` are joined by name, not by a foreign key | On purpose: the phone keys prices by lower-cased item name, including items that aren't in the inventory |
| Budgets and expenses join by category, not a key | A budget is a rule over a category, not a parent row |
| Sync accepts up to 1,000 changes per collection per request (17 collections) | A phone catching up after weeks offline needs it. The request is still rate-limited (60/min) and validated row by row |
| `bootstrap/app.php` holds all the error-format code | It is the one place Laravel 13 wants it; tests pin the format |
| No service layer between controllers and models for CRUD | `HouseholdDataController` is the shared layer; the domain logic (sync, planner, plants) does have its own classes |
| No soft-delete pruning | Tombstones are what sync uses to spread deletes to phones that were offline. Pruning needs a "every device has seen it" rule first |
