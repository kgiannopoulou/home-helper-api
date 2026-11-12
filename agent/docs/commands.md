# Commands

Home Helper can ask a computer to do a few things: ping the router, clear the DNS
cache, restart the media server. It can never run anything else. This page is how
that is guaranteed, and how to add a command.

## The rule

> The server, the dashboard and the assistant can only *name* a command that the
> computer's own config allows, and fill in its declared parameters. The agent checks
> everything again, starts the program directly (no shell), and refuses anything that
> doesn't fit.

There is no endpoint, field or tool that carries a command line.

## Adding a command

In `agent.json` (`home-helper-agent init` writes one with examples):

```json
"commands": [
  {
    "key": "ping_router",
    "description": "Ping a device on the home network four times",
    "program": "C:\\Windows\\System32\\PING.EXE",
    "args": ["-n", "4", "{host}"],
    "params": {
      "host": { "pattern": "^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$",
                "description": "An address on the home network, 192.168.x.x" }
    },
    "timeout_seconds": 20
  },
  {
    "key": "restart_computer",
    "description": "Restart this computer in one minute",
    "program": "C:\\Windows\\System32\\shutdown.exe",
    "args": ["/r", "/t", "60"],
    "sensitive": true
  }
]
```

| Field | |
|---|---|
| `key` | lower case, digits and `_`; what the dashboard and the assistant use |
| `description` | shown on the dashboard and to the assistant |
| `program` | an **absolute path** to an existing file |
| `args` | fixed arguments; a parameter is a whole argument, `"{host}"` |
| `params` | each parameter's regular expression (ECMAScript), matched against the **whole** value |
| `sensitive` | `true`: runs only after the household owner presses Confirm |
| `timeout_seconds` | 1–3600 (default 30); the process tree is killed after it |
| `max_output_kb` | 1–1024 (default 16) |

Then `home-helper-agent check-config` (every problem at once), `home-helper-agent commands`
(what Home Helper will see), and `home-helper-agent run-command ping_router host=192.168.1.1`
to try one exactly as a request would run. Restart the service to load the change; the
dashboard sees the new list at the next heartbeat.

## What the agent refuses

When the config is read:

| Refused | Why |
|---|---|
| a shell or script host as the program: `cmd`, `powershell`, `pwsh`, `bash`, `sh`, `python`, `node`, `perl`, `wscript`, `mshta`, `rundll32`, `env`, `sudo`… | it would turn a parameter into code |
| a script as the program: `.bat`, `.cmd`, `.ps1`, `.vbs`, `.js`… | Windows runs `.bat`/`.cmd` through `cmd.exe`, which re-parses the arguments ("BatBadBut") |
| a relative program, or one that doesn't exist | no `PATH` lookup, no surprises |
| `"--host={host}"`, `"{host}:80"` | a parameter must be a whole argument |
| a parameter that is declared but not used, or used but not declared | a typo would otherwise widen or break the command |

For each request:

| Refused (`rejected`, with the reason) | |
|---|---|
| a key that isn't in this computer's list | whatever the server believes |
| an unknown, missing or non-text parameter | |
| a value that doesn't match its pattern in full | `192.168.1.1; shutdown` fails `^192\.168…$` |
| a value starting with `-`, with a control character, or over 200 characters | can't become an option or a second line |
| a sensitive command not confirmed by the owner | the **local** flag decides, even if the server says it isn't sensitive |
| a request past its `expires_at` | an old request doesn't run late |

## How a command runs

- **Windows**: `CreateProcessW` with the program as `lpApplicationName` (no search),
  each argument quoted for `CommandLineToArgvW`, no window, stdin from `NUL`, only the
  output pipe inherited, inside a Job object that is terminated on timeout, so whatever
  the program started ends too.
- **Linux**: `posix_spawn` (not `posix_spawnp`) in a new process group, stdin from
  `/dev/null`, signals reset to their defaults; `SIGKILL` to the group on timeout.
- One at a time, on a worker thread, so a slow command never delays the heartbeat.
  The same request id runs at most once.
- stdout and stderr together, cut at `max_output_kb`.
- It runs as the agent's service account: **SYSTEM** on Windows, the
  `home-helper-agent` user on Linux (`packaging/linux/`). Write commands with that in mind.
- Every decision, accepted or refused, is logged with the request id: the log is the
  audit trail. The server keeps who asked, who confirmed, and the result.

## Who can ask

| | Non-sensitive | Sensitive |
|---|---|---|
| Household owner, on the dashboard | runs | runs after the owner confirms |
| Household member, on the dashboard | runs | waits for the owner to confirm |
| The assistant | proposes; the person confirms in the chat; then runs | refused: "only the owner, from the Computers page" |

The tests that hold this up: `tests/commands/CommandTests.cpp` (the rules, quoting,
injection attempts reaching a real program as plain text, timeouts) and
`tests/Feature/Agents/CommandsTest.php` (the queue, confirmation, expiry, the assistant).
