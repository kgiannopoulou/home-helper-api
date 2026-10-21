# Sync between phones

The phone stays offline-first: its own storage is the copy you use, and it swaps changes with the server whenever it can. The app side lives in [personal-home-helper](https://github.com/kgiannopoulou/personal-home-helper) (`src/shared/sync`).

```http
POST /api/households/{household}/sync
{
  "since": "2026-10-03T12:00:00.000+00:00",          // null the first time
  "changes": {
    "shopping_items": [{ "id": "01k6…", "name": "Milk", "category": "drinks", "checked": false, "updated_at": "2026-10-03T12:04:31.120Z" }],
    "inventory_items": [{ "id": "01k5…", "updated_at": "2026-10-03T12:05:00.000Z", "deleted_at": "2026-10-03T12:05:00.000Z" }]
  }
}
→ { "since": "…", "changes": { "chores": [ … ] }, "remapped": { "rooms": { "phone-id": "server-id" } }, "rejected": [ … ] }
```

The 18 synced collections are `recurring_bills`, `expenses`, `shopping_trips`, `shopping_items`, `inventory_items` (with their purchase days), `rooms`, `chores`, `chore_completions`, `supplies`, `events`, `todos`, `admin_items`, and, only your own, `food_entries`, `water_entries`, `sleep_entries`, `workouts`, `step_counts`, `weights`.

**Steps** are kept on the phone as `{ "2026-10-04": 8123 }`, without ids. A day's id is made from the person and the day (`StepCount::idFor()` here, `stepsId()` on the phone: a ULID whose time is midnight UTC of the day and whose random part is the user id), so every phone of that person makes the same id and a day is never stored twice. Only finished days sync; today's count changes with every few steps.

**Rules** (`app/Sync/SyncService.php`, one DB transaction):

- **Last write wins** on `updated_at`, the time the row changed *on the device*. An older change never overwrites a newer one. A tie keeps what's stored, so every phone ends up with the same row. A device clock in the future is capped at the server's time, so it can't win every conflict.
- **Deletes are tombstones:** a row arrives with `deleted_at` set and is soft-deleted, so the other phones hear about it.
- **Two clocks.** `updated_at` decides conflicts. `synced_at`, set by MySQL (`DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE …`) on every write, is the cursor. "Changed since my last sync" uses MySQL's clock, so a phone whose clock is behind can't hide its changes. The reply's `since` starts 5 seconds early, so a row still being committed can't fall between two syncs. Getting a row twice is harmless.
- **The same thing made on two phones becomes one.** Both phones start with a "Kitchen" room, or both add this month's rent from the same bill. A new id with the same natural key (`rooms.name`, `inventory_items.name`, `chores (room_id, name)`, `expenses (recurring_bill_id, date)`, a night of sleep's `date`…) joins the existing row, and the reply tells the phone to rename its id (`remapped`). Rows later in the same request that point to it (a chore in that room) follow.
- **Safety:** every row is validated with the same Form Request rules as the REST API, and ids it points to must be in the same household. An id belonging to another household is refused (`forbidden`) and never touched. One bad row is refused on its own (`rejected`), and the rest of the batch still syncs.

Tests: `tests/Feature/Api/SyncTest.php` covers last write wins (older, tie, newer from another time zone, an older deletion), tombstones reaching the other phone, the `since` cursor and its overlap, joining "Kitchen" and the rent, sleep replacing a night, purchase days, future clocks, and refusals.

