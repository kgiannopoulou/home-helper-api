# Testing against a real Home Assistant (day 84)

The Pest tests use `tests/Support/FakeHomeAssistant`, an in-memory Home Assistant behind
`Http::fake()`. That's fast and runs anywhere, but a fake only shows what I already expected.
Day 84 put Home Helper in front of a **real** Home Assistant (2026.11.1, the
[test home](setup.md#the-test-home-docker-2-minutes) in Docker) and broke it on purpose.

## How to run it

```bash
docker compose --profile smart-home up -d homeassistant
ops/home-assistant/scripts/e2e.sh          # ARTISAN, APP, E2E_WAIT: see the script's header
```

The script runs `php artisan smart-home:e2e` in five stages and stops and starts the Home
Assistant container between them. It needs the app running where Home Assistant can reach it,
because the webhook must really arrive. The scheduler must **not** run during the test: every
change the `devices` stage waits for has to come through the webhook, not a sync.

The last run's full output: [`ops/home-assistant/output/e2e.txt`](../../ops/home-assistant/output/e2e.txt).
It passed 57 checks.

| Stage | What it proves |
|---|---|
| `setup` | A throwaway household (owner and member) connects with a real token. All 28 entities are discovered; 17 are imported, and each one lands in the right room from its Home Assistant area |
| `devices` | Light on, Home Assistant switches it, and both sides agree. 40 % becomes brightness 102/255. A member switches a plug. Opening the door in Home Assistant reaches Home Helper by **webhook** and records `door.opened`. A temperature change stores a reading. The *Dehumidifier after showers* workflow switches the plug on at 78 % humidity and off at 55 %, with two runs, both `done`. A member's unlock waits for the owner, the owner approves, and it unlocks; locking needs no approval. An unplugged lamp is refused before anything is sent. Home Assistant rejects a wrong token. The webhook rejects a wrong secret with 401 and ignores a replayed old state |
| `offline` | Home Assistant is stopped. The sync gives up in about 5 s, not 30. The connection says `unreachable`, and `hub.offline` is recorded once. Devices keep their last state. A button press records a failed action within 4 s. An approval given now fails cleanly instead of hanging |
| `recovered` | Home Assistant is started again. The connection heals and records `hub.online` once. Every device answers again, and the lock is still locked. The restart's burst of `unavailable` → `off` creates **no** fake "door opened" events. The dashboard works again |
| `cleanup` | The test household is deleted |

## What the real Home Assistant found

1. **A slow answer held back every change after it.** The forwarding automation ran in
   `mode: queued`. On my Windows dev server every request costs about 3 s (PHP on a bind
   mount), so a door opening waited behind earlier changes and arrived 15+ s late. It now runs
   in `mode: parallel` (max 100). One slow request no longer delays the next.

2. **Parallel pushes exposed an ordering bug.** With pushes in parallel, "door closed at
   +5 s" can arrive before "door opened at +2 s". Home Helper already had the door as closed,
   so the closed push looked like no change and its `last_changed` wasn't stored. The late
   "opened" push then looked newer and won, and the door showed open until the next change.
   Home Assistant only moves `last_changed` when the state changes, so a newer one for the
   same state means a change happened in between. `StateChanges::apply` now stores it too.
   Covered by `FailureScenariosTest` (fails without the fix).

3. **"Fail fast" takes 5 s, not 3, when the name is gone.** With the container stopped,
   Docker's DNS takes about 4 s to give up on the name `homeassistant`, on top of the 3 s
   connect timeout (measured: 4.6 s by name, 3.0 s by IP). On a Pi at home with a fixed IP
   address, it's the 3 s. The check allows 10 s and prints the real number.

4. **Readings are one per minute, by design.** My first check expected a new row for each
   change. `Readings` keeps one row per sensor per minute with the latest value, so the check
   now looks at the value.

5. **An automation's run is written just after Home Assistant switches.** The run row is saved
   when the webhook request that fired it finishes, a moment after the plug changed. The check
   now waits for it instead of reading it at once.

6. **The house must be at rest before a test.** A reset from the previous run can still be on
   its way, and a late humidity push fired the "off" rule once more. The `devices` stage now
   waits until every reset value has arrived before adding rules.

## Failure scenarios in CI

`tests/Feature/SmartHome/FailureScenariosTest.php` keeps these without a real Home Assistant:

| Scenario | Expected |
|---|---|
| Approved while Home Assistant was off | `failed` with a reason; the connection says `unreachable` |
| Home Assistant restarts (everything `unavailable`, then back, in any order) | no `door.opened`, `window.opened` or `motion.detected` events |
| Pushes out of order | the newest `last_changed` wins, also for the same state |
| An automation fires while Home Assistant is off | the run is `failed`; the next trigger works |
| A router login page, a 500, a revoked token | a `failed` action with a readable error, never an exception |
| Several failed calls, then recovery | one `hub.offline`, one `hub.online` |

Together with the existing tests for wrong webhook secrets, unknown entities, oversized
attributes, devices removed from Home Assistant and unavailable devices: 158 smart-home tests.

## Real hardware

I don't own the devices yet. The guide pointed at a BroadLink/Smart Life radar motion kit and
smart lights from MediaMarkt; [setup.md](setup.md#hardware-i-dont-have-yet) has the list. The
test home is a real Home Assistant with the same API, events and service calls. Only the
devices are simulated, so the part Home Helper owns is tested for real. What it can't show,
and what to check in a real house:

- [ ] Radio range and a Zigbee router dropping out: devices go `unavailable` for minutes, and the "device stops answering" push should fire once
- [ ] Battery sensors: a flat battery should never count as an opening (tested with the simulated `unavailable`)
- [ ] Tuya/Smart Life cloud devices: their state can lag Home Assistant by seconds, and `last_changed` should still order them
- [ ] A Pi reboot and a power cut: the same restart burst as the `recovered` stage
- [ ] Unlocking from the phone, away from home, through the reverse proxy
- [ ] Home Assistant's clock: future `last_changed` is capped at Home Helper's now, so NTP on the Pi matters
