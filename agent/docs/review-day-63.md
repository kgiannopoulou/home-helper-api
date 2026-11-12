# Review, day 63

Halfway through Phase 5: enrollment, signed requests, the heartbeat and local
monitoring are in. Before adding anything that *does* things on the computer
(commands, updates), a review of what exists.

## What was checked

| Check | How | Result |
|---|---|---|
| The agent builds and passes its tests on Linux and Windows | `agent` workflow: GCC + libcurl on Ubuntu, MSVC + WinHTTP on Windows | green, first run |
| Memory errors and undefined behaviour | the same tests under Clang ASan + UBSan | green |
| C++ and PHP agree on every signature byte | `tests/vectors/signing.json`, made with PHP's `hash()` and `sodium`, read by Catch2 and Pest | green |
| The agent works against the real app, not just fakes | Laravel served on port 8077, `php artisan agent:pair`, then `enroll`, `status` and `run --once` from the Windows build | enrolled; `status` got a signed, verified answer; the heartbeat stored readings |
| Real readings are sensible | `home-helper-agent monitor` on the development laptop (Ryzen 7 5800H, 16 threads, 16 GB) | CPU %, memory and the C: drive match Task Manager; no temperatures (below) |
| Every API route is protected | `tests/Feature/Api/AuthorizationTest.php` now also sweeps the agent routes: each one refuses an unsigned request with `missing_signature` | green |
| Static analysis and style on the PHP side | PHPStan level 7, Pint | **failed**, fixed below |
| Generated docs | `scramble:export`, `docs:schema` | **out of date**, fixed below |

## Findings and fixes

1. **PHPStan: untyped collections in `App\Agents\Heartbeats`.** `collect()` over
   request arrays could not infer its types. Rewritten as plain loops that also
   skip malformed entries instead of trusting their shape.
2. **PHPStan: `AgentMetric::prunable()` return type.** Declared `Builder<AgentMetric>`
   and made the model `final`.
3. **Pint: an import added out of order** in `routes/console.php`.
4. **The OpenAPI document and the ER diagram** did not include the agent routes and
   tables. Regenerated; the diagram has a new *Computers (local agent)* module.
5. **The git commit baked into the binary was stale.** It was read when CMake
   configured, not when it built, so `version` and the heartbeat could report an
   older commit than the one running. It is now regenerated on every build
   (`cmake/GitVersion.cmake`); the header is only rewritten when it changes, so this
   costs no rebuilds.
6. **Mixed slashes in paths** from a config with `data_dir: "D:/hh"`. Normalised.

## Things that are as designed, written down

- **No temperatures on most Windows desktops.** Windows only exposes ACPI thermal
  zones, which many boards don't implement, and `MSAcpi_ThermalZoneTemperature`
  needs administrator rights (the service has them; a console run usually does not).
  The agent says so once, then checks every 30 minutes instead of every heartbeat.
  Linux reads hwmon (`coretemp`, `k10temp`, `nvme`, the Pi's `cpu_thermal`).
- **Response signatures on rejections.** `unknown_agent` and `bad_signature` can't be
  signed (no trustworthy secret); `agent_revoked`, `clock_skew` and `replayed` are,
  so the agent only acts on those when they are genuine.
- **The secret is stored encrypted, not hashed.** HMAC needs the key on both sides.
  It is encrypted with `APP_KEY`; rotating `APP_KEY` means re-enrolling the computers
  (docs/agents.md).

## Next

Days 64–70: audio devices and the inventory, the command allowlist, events with an
offline outbox, the service, reconnect, signed updates, and the dashboard.
