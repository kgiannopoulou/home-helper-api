# Protocol

The agent talks to Laravel over HTTPS, JSON in both directions, always starting the
request itself. Every endpoint is under `/api/agent/v1/`. The Laravel side is
`routes/api.php` → `App\Http\Controllers\Api\Agent\*`; the C++ side is `src/net/` and
`src/agent/`.

## Signatures

After enrollment every request carries four headers:

| Header | Value |
|---|---|
| `X-HH-Agent` | the agent id (a ULID) |
| `X-HH-Timestamp` | unix seconds |
| `X-HH-Nonce` | 16 random bytes, 32 hex characters, never reused |
| `X-HH-Signature` | HMAC-SHA-512 of the canonical request, 128 hex characters |

The canonical request is seven lines joined by `\n`, with no trailing newline:

```
HH-AGENT-V1
POST
/api/agent/v1/heartbeat        (the path as sent, below any sub-directory the app lives in)
1794470400
00112233445566778899aabbccddeeff
01JCXK3Z9Q0000000000000000
<sha512 hex of the exact body bytes; of "" for a GET>
```

The server rejects, with `401` and an `error` code:

| `error` | Why |
|---|---|
| `missing_signature` | a header is missing or malformed |
| `unknown_agent` | no such agent |
| `agent_revoked` | the owner removed this computer: the agent stops and says so |
| `clock_skew` | the timestamp is more than 300 s away; the reply has `server_time`, and the agent corrects its clock offset and retries once |
| `bad_signature` | the HMAC does not match |
| `replayed` | the nonce was seen in the last 10 minutes |

Every response to a signed request (errors too) carries `X-HH-Signature`, the HMAC of:

```
HH-AGENT-V1-RESPONSE
200
<the request's nonce>
<sha512 hex of the response body>
```

The agent acts on nothing from a 2xx response whose signature is missing or wrong. Because
the request's nonce is in it, an old response can't be replayed against a new request.

`tests/vectors/signing.json` holds requests, responses and a signed release, generated
with PHP's `hash()` and `sodium`. The Catch2 tests (`tests/net/SigningTests.cpp`) and the
Pest tests (`tests/Feature/Agents/SignatureVectorsTest.php`) both check them.

## Endpoints

### `POST enroll` (unsigned)

The one request without a signature: the pairing code is the proof. Rate-limited per IP.

```json
{ "code": "K7QM-2XPA", "name": "Living-room mini PC", "hostname": "MINIPC",
  "platform": "windows-x64", "version": "0.5.0" }
```

`201`:

```json
{ "agent_id": "01JD…", "secret": "<64 hex characters>",
  "household": { "id": "01J…", "name": "Home" }, "heartbeat_seconds": 30 }
```

`422 invalid_code` for a code that is wrong, used or expired (all three look the same).
The secret is shown this once; the server keeps it encrypted (`APP_KEY`).

### `POST heartbeat`

Every `heartbeat_seconds` (30 by default). Carries the latest metrics; the reply carries
work.

```json
{
  "version": "0.5.0", "platform": "windows-x64", "git_sha": "64fe33f0a1b2",
  "started_at": "2026-11-12T10:00:00Z", "uptime_seconds": 86400,
  "metrics": {
    "cpu_percent": 12.5,
    "memory": { "total_bytes": 17179869184, "used_bytes": 8589934592 },
    "disks": [ { "mount": "C:\\", "total_bytes": 512000000000, "free_bytes": 128000000000 } ],
    "temperatures": [ { "label": "CPU", "celsius": 54.0 } ],
    "load": [0.5, 0.4, 0.3]
  },
  "outbox": { "pending": 0, "dropped": 0 },
  "inventory_hash": "<sha512 of the last inventory sent>"
}
```

`200`:

```json
{
  "server_time": 1794470400,
  "heartbeat_seconds": 30,
  "inventory_wanted": false,
  "commands": [
    { "id": "01JD…", "key": "ping_router", "params": { "host": "192.168.1.1" },
      "sensitive": false, "confirmed": false, "expires_at": "2026-11-12T10:05:00Z" }
  ],
  "update": null
}
```

`update`, when the server has a newer signed release for this platform:

```json
{ "version": "0.6.0", "platform": "windows-x64", "url": "https://…/home-helper-agent.exe",
  "sha512": "…", "size": 1234567, "signature": "<Ed25519, 128 hex>", "install": false }
```

`install` is `true` only after the household owner pressed *Install* on the dashboard.

### `POST inventory`

On start, whenever it changes, and when the heartbeat reply says `inventory_wanted`.

```json
{
  "hostname": "MINIPC",
  "os": { "name": "Windows", "version": "10.0.26300" },
  "cpu": { "model": "Intel(R) N100", "cores": 4 },
  "memory_bytes": 17179869184,
  "disks": [ { "mount": "C:\\", "total_bytes": 512000000000, "filesystem": "NTFS" } ],
  "audio": [
    { "id": "{0.0.1.00000000}.{…}", "name": "Microphone (USB Audio)", "direction": "input",
      "default": true, "state": "active" }
  ],
  "commands": [
    { "key": "ping_router", "description": "Ping the router four times", "sensitive": false,
      "params": [ { "name": "host", "description": "IPv4 address", "pattern": "^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$" } ] }
  ]
}
```

`204`. The server stores the allowlist only to offer it on the dashboard; it can't change it.

### `POST events`

Up to 100 events per request, oldest first, from the agent's outbox.

```json
{ "events": [ { "id": "6f1c…(uuid4)", "type": "disk.low", "severity": "warning",
  "message": "C:\\ has 4.1 GB free (3%)", "data": { "mount": "C:\\", "free_percent": 3.2 },
  "occurred_at": "2026-11-12T10:00:00.123Z" } ] }
```

`200 {"accepted": 1, "duplicates": 0}`. The id makes a resend after a lost reply harmless.

Types: `agent.started`, `agent.stopping`, `agent.reconnected`, `cpu.high`, `cpu.normal`,
`memory.high`, `memory.normal`, `disk.low`, `disk.normal`, `temperature.high`,
`temperature.normal`, `audio.added`, `audio.removed`, `command.rejected`,
`update.available`, `update.staged`, `update.failed`.

### `POST commands/{id}/result`

```json
{ "status": "succeeded", "exit_code": 0, "output": "…(at most 16 KB)…", "truncated": false,
  "duration_ms": 3021, "started_at": "2026-11-12T10:00:00Z", "reason": null }
```

`status` is `succeeded`, `failed` (non-zero exit), `timed_out`, or `rejected` (with a
`reason`: not on this computer's allowlist, a parameter that doesn't match, sensitive but
not confirmed, expired). `204`. A result for a command that is already finished is
ignored, so the agent can resend it after a lost reply.
