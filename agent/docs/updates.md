# Updates

An agent can be updated from the dashboard, but only to a release signed with a key the
computer itself trusts, and only when the household owner presses **Install**.

## Why it is safe

| Threat | What stops it |
|---|---|
| Someone takes over the Home Helper server and offers a malicious "update" | The agent checks an **Ed25519 signature** against the public key in its *own* `agent.json` before downloading anything. The server never has to hold the secret key (keep it on the machine that builds releases). |
| The download is swapped or corrupted on the way | The signature covers the file's **SHA-512 and size**; the agent checks both after downloading. Server downloads are also signed per response like every agent request. |
| The staged file is changed on disk before it is installed | Size, SHA-512 and signature are checked **again** right before the swap. |
| An old, vulnerable version is offered again | The agent **never downgrades**: only versions newer than its own are considered. |
| A release for another platform | The platform is in the signed manifest and must match. |
| An update nobody asked for | The agent only reports `update.available`; it downloads when the offer says `install`, which only the owner's Install button sets. |
| A bad release | The previous binary is kept as `.old`; `home-helper-agent update rollback` puts it back. The server records `agent.updated` with a warning when a version goes *down*. |

The signed manifest is five lines:

```
HH-AGENT-RELEASE-V1
0.6.0
windows-x64
<sha512 hex of the file>
<size in bytes>
```

`tests/vectors/signing.json` has one signed by PHP's sodium; the C++ tests verify it with
Monocypher, and the PHP tests with sodium.

## Releasing

Once:

```sh
php artisan agent:release-key
```

prints a key pair. The secret goes in the `.env` of whatever signs releases
(`AGENT_RELEASE_SECRET_KEY`, with `AGENT_RELEASE_PUBLIC_KEY` so the server can check it
signed with the right one); the public key goes in every computer's config:

```json
"updates": { "public_key": "d75a98…511a" }
```

No public key, no updates: the agent logs that one is offered and leaves it.

For each release, after bumping `project(VERSION …)` in `agent/CMakeLists.txt` and building:

```sh
php artisan agent:release 0.6.0 windows-x64 agent/build/home-helper-agent.exe --notes "Faster heartbeats"
php artisan agent:release 0.6.0 linux-arm64 build-pi/home-helper-agent
# or keep the file elsewhere: --url https://github.com/…/releases/download/v0.6.0/home-helper-agent.exe
```

## Installing

1. The next heartbeat offers the release; the agent verifies the signature and sends
   `update.available`. The Computers page shows "0.6.0 available".
2. The owner presses **Install**. The next heartbeat's offer says `install: true`.
3. The agent downloads, verifies, stages it in `<data_dir>/updates/staged/` (`update.staged`),
   and exits with code 75.
4. The service manager starts it again (75 counts as a failure, so both the SCM's recovery
   actions and systemd's `Restart=on-failure` restart it):
   - **Windows**: before exiting, the agent renames its own running `.exe` to `.exe.old` (Windows
     allows renaming a running executable) and puts the new one in its place.
   - **Linux**: the service user can't write `/usr/local/bin`, so the unit's
     `ExecStartPre=+… update apply-staged` does the swap, with root rights for that one step,
     after verifying again.
5. The new version's first heartbeat reports it; the server records `agent.updated` and the
   Install request is done.

`home-helper-agent update status` shows the running version, whether updates are on, and any
staged release. `update rollback` restores the previous binary.
