# Running as a service

The agent is meant to run all the time, from boot, without anyone logged in.

## Windows

From an elevated PowerShell:

```powershell
.\agent\packaging\windows\install.ps1 -Server https://home.example.org -Code K7QM-2XPA
```

which copies the binary to `%ProgramFiles%\HomeHelperAgent`, writes the config if there
isn't one, enrolls, and runs `home-helper-agent service install`. By hand:

```powershell
home-helper-agent init --server https://home.example.org
notepad $env:ProgramData\HomeHelperAgent\agent.json      # review the commands
home-helper-agent enroll --code K7QM-2XPA
home-helper-agent service install                        # creates and starts it
home-helper-agent service status                         # running
```

What `service install` sets up:

| | |
|---|---|
| Name | `HomeHelperAgent` ("Home Helper Agent" in services.msc) |
| Account | LocalSystem: it reads WMI temperatures and runs allowlisted commands like `shutdown` |
| Start | automatic, delayed (after boot, once the network is up) |
| Command | `"…\home-helper-agent.exe" --config "…\agent.json" run --service` |
| Recovery | restart after 10 s, then 60 s, then every 5 min; failures forgotten after a day; an exit with an error counts as a failure, not only a crash |
| Privileges | only `SeShutdownPrivilege`, `SeChangeNotifyPrivilege`, `SeIncreaseQuotaPrivilege`, `SeAssignPrimaryTokenPrivilege`: LocalSystem's others (backup, restore, debug, take ownership…) are removed from the process, and so from every command it starts |
| Folders | `%ProgramData%\HomeHelperAgent` and its `data` folder: SYSTEM and Administrators only, inherited |

Logs: `%ProgramData%\HomeHelperAgent\data\logs\agent.log`.

`service stop`, `service start`, `service uninstall` (keeps the config, credentials and logs).

## Linux (systemd), including the Raspberry Pi

```sh
cmake -S agent -B agent/build -DCMAKE_BUILD_TYPE=Release && cmake --build agent/build -j4
sudo agent/packaging/linux/install.sh --server https://home.example.org --code K7QM-2XPA
sudoedit /etc/home-helper-agent/agent.json      # review the commands
sudo systemctl enable --now home-helper-agent
journalctl -u home-helper-agent -f
```

The unit ([`home-helper-agent.service`](../packaging/linux/home-helper-agent.service)) runs as
a dedicated system user with no shell, and is locked down: read-only file system except its
state directory, no new privileges, no capabilities, a system-call filter. Check it with
`systemd-analyze security home-helper-agent`.

Commands run with the same restrictions, which is the point. For one that needs more, grant
exactly that: [`polkit-restart-units.rules.example`](../packaging/linux/polkit-restart-units.rules.example)
lets the agent's user restart two named units, and nothing else, so
`systemctl restart jellyfin.service` works without root or sudo. (`ping` works through
unprivileged ICMP sockets on current distributions; `NoNewPrivileges` blocks its file
capability on older ones.)

## Stopping, and when it stops by itself

| Exit | Meaning | Restarted? |
|---|---|---|
| 0 | stopped (Ctrl+C, `service stop`, `systemctl stop`) | no |
| 3 | this computer was removed from the household on the dashboard | **no**: `RestartPreventExitStatus=3`; reported to the SCM as a clean stop |
| other | crashed or failed | yes |

On stop the agent finishes the command that is running (up to 10 s), sends what is in the
outbox if the server is there, and keeps the rest on disk for next time.
