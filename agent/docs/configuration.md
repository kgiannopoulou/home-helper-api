# Configuration

One JSON file, written by the person who installs the agent. The server never sends
any of it and can't change it.

| | Default location |
|---|---|
| Windows | `%ProgramData%\HomeHelperAgent\agent.json` |
| Linux | `/etc/home-helper-agent/agent.json` |

Any command takes `--config FILE` to use another one. Comments (`//`, `/* */`) are allowed.
[`agent.schema.json`](agent.schema.json) gives editors completion and checks; the
`$schema` line that `init` writes points at it.

```sh
home-helper-agent init --server https://home.example.org   # a starting file, with example commands
home-helper-agent check-config                             # every problem at once, not just the first
```

## Settings

| Setting | Default | |
|---|---|---|
| `server_url` | | Home Helper's address. Only `enroll` uses it; afterwards the server comes from the credentials. `https://`, or `http://` to localhost |
| `name` | the hostname | shown on the dashboard |
| `allow_insecure_http` | `false` | plain `http://` to another machine. A lab only: requests stay signed, but readings travel in clear text |
| `ca_file` | | Linux: a PEM bundle to trust instead of the system's (a home CA). On Windows, add the CA to the machine's certificate store |
| `data_dir` | `%ProgramData%\HomeHelperAgent\data`, `/var/lib/home-helper-agent` | credentials, outbox, logs, staged updates |
| `log.level` | `info` | `trace` … `off` |
| `log.file` | `true` | also `<data_dir>/logs/agent.log`, 5 MB × 3 |
| `heartbeat_seconds` | `30` | until the server says otherwise (`AGENT_HEARTBEAT_SECONDS` on the server) |
| `inventory_hours` | `6` | send the inventory at least this often (it is also sent whenever it changes) |
| `monitor.cpu_high_percent` | `90` | `cpu.high` after `sustain_samples` readings over it |
| `monitor.memory_high_percent` | `90` | `memory.high` |
| `monitor.disk_low_free_percent` | `10` | `disk.low` per disk; critical under half of it |
| `monitor.temperature_high_c` | `85` | `temperature.high`; critical 10 °C over |
| `monitor.sustain_samples` | `3` | readings in a row before an event, and before it clears |
| `monitor.disks` | all fixed disks | only these: `["C:\\", "D:\\"]`, `["/", "/srv"]` |
| `audio.enabled` | `true` | list microphones and speakers (they are never opened) |
| `outbox.max_events` | `1000` | events kept while offline; older ones are dropped and counted |
| `commands` | none | the allowlist: [commands.md](commands.md) |
| `updates.public_key` | none | the Ed25519 key releases must be signed with: [updates.md](updates.md). No updates without it |

## Files in the data directory

| File | |
|---|---|
| `credentials.json` | agent id, server, and the secret: DPAPI-encrypted with an ACL on Windows, mode 0600 on Linux |
| `outbox.jsonl` | events and command results waiting to be sent |
| `logs/agent.log` | the log, including every command decision |
| `updates/` | downloaded, verified releases waiting to be installed |

`home-helper-agent status` shows where everything is and whether the server accepts the
credentials.

## Changing it

Edit the file, run `check-config`, then restart the service (`home-helper-agent service stop`
and `start`, or `systemctl restart home-helper-agent`). A config with a mistake stops the
agent from starting rather than starting with half of it, and the log says what is wrong.
