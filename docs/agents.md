# Computers (the local agent), server side

Home Helper reaches the computers in the house through a small C++ agent that runs on
each of them. The agent has its own documentation in [`agent/`](../agent/README.md); this
page is the Laravel side: what the app stores, which endpoints the agent calls, and how
people and the assistant use it.

## Pieces

| | |
|---|---|
| `app/Agents/RequestSignature` | the signature format, shared byte for byte with the agent (`agent/tests/vectors/signing.json`) |
| `app/Http/Middleware/AuthenticateAgent` | signature, clock skew, replayed nonces, revoked agents; signs every response |
| `app/Agents/Enrollment` | one-time pairing codes → an agent id and a 256-bit secret (encrypted with `APP_KEY`) |
| `app/Agents/Heartbeats` | status, readings, one chart row a minute; the reply carries commands and update offers |
| `app/Agents/Inventory` | system, disks, microphones and speakers, the allowlist the agent reports |
| `app/Agents/Commands` | asking for an allowlisted command, the owner's confirmation, results |
| `app/Agents/AgentEvents` | events from the agents and about them; warnings pushed to phones |
| `app/Agents/Releases` | Ed25519-signed releases, offers, the owner's Install |
| `app/Http/Controllers/Api/Agent/*` | `/api/agent/v1/…` ([protocol](../agent/docs/protocol.md)) |
| `app/Http/Controllers/Web/ComputersController` | the Computers page |
| `app/Ai/Tools/Computers/*` | `get_computers`, `run_computer_command` |

Tables: `agents`, `agent_enrollment_codes`, `agent_metrics` (7 days), `agent_commands`,
`agent_events` (90 days), `agent_releases` ([diagram](database.md)).

## Who may do what

| | Owner | Member | Assistant |
|---|---|---|---|
| See computers, readings, events | ✓ | ✓ | ✓ (`get_computers`) |
| Run a non-sensitive allowlisted command | ✓ | ✓ | proposes it; the person confirms in the chat |
| Run a sensitive one | ✓ after confirming | asks; the owner confirms | ✗ refused |
| Pair, rename, remove, install updates | ✓ | ✗ | ✗ |

Whatever the server decides, the agent checks the command against its own allowlist
again and refuses anything else: the server can't widen what a computer allows.

## Endpoints the agent calls

| | |
|---|---|
| `POST /api/agent/v1/enroll` | unsigned; the pairing code is the proof; 5 a minute per address |
| `GET  /api/agent/v1/me` | who the server thinks this agent is (`home-helper-agent status`) |
| `POST /api/agent/v1/heartbeat` | every 30 s |
| `POST /api/agent/v1/inventory` | when it changes |
| `POST /api/agent/v1/events` | up to 100 at a time, resends recognised |
| `POST /api/agent/v1/commands/{id}/result` | idempotent |
| `GET  /api/agent/v1/releases/{id}/download` | the release file, for that platform |

All but `enroll` are signed and throttled at 60 a minute per agent. The route sweep in
`tests/Feature/Api/AuthorizationTest.php` proves each one refuses an unsigned request.

## Scheduled and command-line

| | |
|---|---|
| `agents:check-offline` (every minute) | one `agent.offline` per outage, pushed to the phones; the next heartbeat records `agent.online` |
| `model:prune` (daily) | chart rows after 7 days, events after 90 |
| `agent:pair [--email] [--name]` | a pairing code without the dashboard |
| `agent:admin list\|show\|run\|confirm\|revoke` | the Computers page from a terminal |
| `agent:release-key` | an Ed25519 key pair for releases |
| `agent:release VERSION PLATFORM FILE [--url] [--notes]` | sign and offer a release |

## Settings (`config/homehelper.php` → `agents`)

| | Default | |
|---|---|---|
| `AGENT_HEARTBEAT_SECONDS` | 30 | sent to the agents in every reply |
| `offline_after_heartbeats` | 4 | offline after two minutes of silence |
| `clock_skew_seconds` | 300 | signed requests older or newer than this are refused (the agent corrects itself) |
| `nonce_minutes` | 10 | how long a nonce is remembered |
| `command_minutes` | 10 | a command not picked up by then expires |
| `output_kb` | 16 | command output kept |
| `AGENT_RELEASE_PUBLIC_KEY`, `AGENT_RELEASE_SECRET_KEY` | | release signing ([updates](../agent/docs/updates.md)) |

Rotating `APP_KEY` makes the stored agent secrets unreadable: re-enroll the computers.
