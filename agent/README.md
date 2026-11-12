# Home Helper Agent

[![agent](https://github.com/kgiannopoulou/home-helper-api/actions/workflows/agent.yml/badge.svg)](https://github.com/kgiannopoulou/home-helper-api/actions/workflows/agent.yml)

A small C++20 program that connects the computers in your home to [Home Helper](../README.md).
It runs as a service on a mini PC, a desktop or a Raspberry Pi and:

- **reports** CPU, memory, disks, temperature, microphones and speakers, online or offline;
- **sends events**: a disk filling up, a machine running hot, a microphone plugged in;
- **runs a short list of commands** when someone asks, and nothing else: the list is in the
  computer's own config, sensitive ones need the household owner's confirmation, and there
  is no shell;
- **updates itself** to releases signed with a key it trusts, when the owner says so.

```
$ home-helper-agent status
home-helper-agent 0.5.0 on MINIPC
  config       C:\ProgramData\HomeHelperAgent\agent.json
  data dir     C:\ProgramData\HomeHelperAgent\data
  enrolled     01m77y19ch21d48rz0n1kr6tky in "Home"
  server       https://home.example.org
  server says  OK, this is "Living-room mini PC"
```

## Try it

```sh
# Build (Linux: needs libcurl; Windows: agent\scripts\build.bat with the VS Build Tools)
cmake -S agent -B agent/build -DCMAKE_BUILD_TYPE=Release && cmake --build agent/build -j
ctest --test-dir agent/build

# On the server: a one-time pairing code (or the Computers page)
php artisan agent:pair

# On the computer
home-helper-agent init --server https://home.example.org   # a config with example commands
home-helper-agent enroll --code K7QM-2XPA
home-helper-agent monitor                                  # what it would report
home-helper-agent run                                      # or install the service
```

## Commands

| | |
|---|---|
| `init --server URL` | write a starting config |
| `check-config` | list every problem in the config at once |
| `enroll --code CODE` | pair with a household |
| `status` | config, credentials, and whether the server accepts them |
| `monitor`, `audio`, `commands` | what it would report, its audio devices, its allowlist |
| `run-command KEY name=value` | try one allowlisted command locally |
| `run [--once]` | connect and keep reporting |
| `service install\|start\|stop\|status\|uninstall` | the Windows service |
| `update status\|apply-staged\|rollback` | signed updates |
| `unenroll` | forget the credentials |

## How it is built

```
src/core      args, logging (spdlog), paths, SemVer, time, host
src/crypto    SHA-512, HMAC, Ed25519 (Monocypher), the OS random generator
src/net       URL, WinHTTP / libcurl transports, request and response signatures, the API client
src/agent     config, credentials (DPAPI / 0600), enrollment, heartbeat, inventory, backoff, the loop
src/monitor   CPU, memory, disks, temperatures: Windows (Win32, WMI) and Linux (/proc, /sys)
src/audio     microphones and speakers: Core Audio and ALSA
src/commands  the allowlist, starting processes without a shell, the worker
src/events    events, thresholds with hysteresis, the on-disk outbox
src/update    signed releases: verify, stage, swap, roll back
src/service   the Windows service
apps/agent    the CLI
tests/        Catch2: 105 tests, shared signature vectors with the Laravel tests
packaging/    systemd unit and install script, Windows install script
scripts/      build.bat, e2e.sh (the real agent against the real app)
```

Every dependency is pinned by URL and SHA-256; Monocypher is vendored. CI builds and tests
on Ubuntu (GCC, libcurl) and Windows (MSVC, WinHTTP), runs the tests under ASan and UBSan,
and runs `scripts/e2e.sh` against the Laravel app on MySQL.

## Documentation

| | |
|---|---|
| [Design](docs/design.md) | goals, non-goals, trust model, security decisions |
| [Protocol](docs/protocol.md) | every endpoint, the signature format, test vectors |
| [Commands](docs/commands.md) | the allowlist and why it is safe |
| [Configuration](docs/configuration.md) | every setting ([JSON Schema](docs/agent.schema.json)) |
| [Service](docs/service.md) | Windows service and systemd |
| [Updates](docs/updates.md) | signed releases, install, rollback |
| [Review, day 63](docs/review-day-63.md) | what the halfway review found |
| [Server side](../docs/agents.md) | the Laravel endpoints, tables, the Computers page |

It lives in this repository so the agent, the Laravel API it talks to and the dashboard change
together in one pull request. It is a self-contained CMake project with no dependency on the
rest of the repository: `git subtree split --prefix agent` turns it into its own repository.
