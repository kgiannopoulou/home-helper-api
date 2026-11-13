# Phase 6: Smart home integration

**Goal:** connect the actual home.
**Deliverable:** Home Helper sees and controls lights, switches and sensors through the
household's Home Assistant, runs automations, and asks the owner before anything risky.
**Done:** 2026-11-13. How it works: [smart-home.md](smart-home.md). Setup and the Pi:
[smart-home/setup.md](smart-home/setup.md). Real-device testing:
[smart-home/testing.md](smart-home/testing.md).

| Day | Task | Result |
|---:|---|---|
| 71 | Home Assistant at home | A **real** Home Assistant in Docker (`--profile smart-home`) with simulated devices: lights, plugs, temperature and humidity, doors, a window, motion and a lock, with `sim_*` helpers standing in for the physical world. `onboard.py` does the onboarding wizard and areas through the API and prints a token. Raspberry Pi install guide |
| 72 | Connect it to Laravel | Both ways: Home Helper → Home Assistant with a long-lived token (checked before saving, encrypted at rest, 5 s timeout, no redirects); Home Assistant → Home Helper with a webhook and its own secret (hashed, rotatable). `packages/home_helper.yaml` forwards state changes |
| 73 | Discovery and sync | `smart-home:sync` every minute: new, removed and unavailable devices, rooms from Home Assistant areas. One `StateChanges::apply` for webhook, sync and replies: the newest `last_changed` wins, real changes announced once |
| 74 | Import lights, switches, sensors | The owner chooses what Home Helper shows. Capabilities from Home Assistant's attributes (dimmable, device class, units) |
| 75 | Smart-home dashboard | The Smart home page: rooms of device tiles, the house at a glance (lights on, doors, temperature, humidity), also on the main dashboard |
| 76 | Light and switch controls | On, off, toggle and dimming from the page and the phone API, through one `Control` class: permissions, device checks, an audit row per action, the reply applied at once |
| 77 | Rest and review | PHPStan types, generated docs current, webhook route naming. [Review](smart-home/review-day-77.md) |
| 78 | Temperature and humidity | Readings history (one per minute at most, a point every 15 min), comfort and dew point per room, 24-hour charts, very-humid and cold alerts |
| 79 | Door and window sensors | Door, window and motion sensors to events with durations, "left open" warnings (10 min doors, 30 min windows), the house's event log. `unavailable` is never an opening |
| 80 | Event notifications | Pushes per event type (always, at night, never), throttled to one per 15 minutes; devices and Home Assistant going quiet, once per outage |
| 81 | Automation rules engine | Trigger → conditions → actions in fixed, validated shapes; time, sun, days, state and value conditions; every match recorded with why it did or didn't run; cooldowns and a depth limit against loops; actions through `Control` |
| 82 | First household workflows | Six ready-made workflows (welcome light, dehumidifier after showers, good night, window at night, motion light, cold room with an open window) filled in from the household's own devices |
| 83 | Manual approval for sensitive actions | Locks, climate and things that heat are sensitive; only the risky direction waits. Members, automations and the assistant ask, the owner approves on the page or the phone, the device is checked again, and requests expire after 10 min. The assistant gets `get_smart_home` and `control_device` and can never unlock |
| 84 | Real devices and failure scenarios | `ops/home-assistant/scripts/e2e.sh` runs Home Helper against the real Home Assistant, including stopping and restarting it: **57 checks pass**. It found two real problems: queued forwarding let one slow answer delay a door alert, and parallel forwarding then exposed an out-of-order bug. Both are fixed, and 8 failure-scenario tests keep them fixed. [Testing](smart-home/testing.md) |

## In numbers

- 8 migrations, 7 tables, 4 API endpoints plus the webhook, 18 dashboard routes, 2 assistant tools, 5 artisan commands (3 scheduled every minute).
- Pest: 599 → **757** (158 smart-home tests); Vitest: 53 → **65**.
- An end-to-end run against a real Home Assistant 2026.11.1: 57 checks in five stages.

## Safety, in one table

| Concern | Answer |
|---|---|
| Can a member or an automation unlock the front door? | They can ask. The owner gets a push and approves or declines; unanswered requests expire after 10 minutes. |
| Can the assistant? | No, not even for the owner: it can only create the request. Everyday devices need the person's confirmation in the chat. |
| Can someone fake a door opening? | The webhook needs the connection's own secret (not a user token), is rate-limited, and ignores states older than what Home Helper has. |
| What if Home Assistant is off? | Calls give up in seconds, the page says why, the phones are told once, and nothing waits forever. |
| What if a token leaks from the database? | Home Assistant's token is encrypted with `APP_KEY`; the webhook secret is stored only as a hash. |

## Not done yet

- Real hardware. The guide's radar motion kit and smart lights aren't bought yet; [the checklist](smart-home/testing.md#real-hardware) is ready for them.
- The phone app's smart-home screen. The API is there and the approval pushes arrive, but there is no native screen yet.
- Covers, climate and fans are discovered and shown, but have no controls besides locks, lights and switches.
- A CI job with the real Home Assistant. The end-to-end script runs locally; the image is about 1.5 GB.
