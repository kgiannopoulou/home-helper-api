# Design

Home Helper (the [Laravel app](https://github.com/kgiannopoulou/home-helper-api)) knows the
household: bills, chores, plants, the assistant's memory. It does not know anything about
the computers in the house. This agent is the bridge: a small C++ program that runs as a
service on each computer (a mini PC under the TV, a desktop, a Raspberry Pi), reports what
it sees and does a short list of things when asked.

## Goals

1. **Visibility.** CPU, memory, disks, temperature, microphones and speakers, online or
   offline: on the dashboard and available to the assistant ("is the mini PC running hot?").
2. **A few useful actions.** Ping the router, lock the screen, restart a service. Each one
   is written into the agent's configuration by the person who owns the computer.
3. **Safe by construction.** The server, and the AI behind it, can never run anything that
   is not on that list. Sensitive actions also need a person to press Confirm.
4. **Robust at home.** Wi-Fi drops, the server restarts, the laptop sleeps. The agent keeps
   working offline, keeps its events and reports them when it is back.

## Non-goals

- A remote shell. There is no "run this command" endpoint and there never will be.
- Recording audio. The agent lists audio devices; it never opens them.
- Remote desktop, file transfer, or managing computers outside the household.

## Shape

```
 ┌──────────────── computer ────────────────┐          ┌──────────── Home Helper ────────────┐
 │ home-helper-agent (service)               │  HTTPS   │ Laravel                              │
 │                                           │  signed  │                                      │
 │  monitor ─┐                               │ ───────▶ │ /api/agent/v1/heartbeat   metrics    │
 │  audio ───┼─▶ agent loop ─▶ api client ───┼──────────│ /api/agent/v1/inventory   devices    │
 │  events ──┘      │    ▲                   │ ◀─────── │   ← queued commands, update offers   │
 │                  ▼    │ results/events    │          │ /api/agent/v1/events      events     │
 │      command worker ──┘  (outbox on disk) │          │ /api/agent/v1/commands/…  results    │
 │        allowlist → process, no shell      │          │                                      │
 └───────────────────────────────────────────┘          │ dashboard /computers, assistant tools│
                                                        └──────────────────────────────────────┘
```

The agent only ever makes outgoing requests: it works behind NAT, opens no port and needs
no firewall rule. Commands reach it in the reply to its heartbeat, every 30 seconds.

## Trust model

| Who | Trusted with |
|---|---|
| The person who installs the agent | Everything: they write the allowlist and the config |
| The household owner on the dashboard | Pairing and revoking computers, confirming sensitive commands |
| Household members | Seeing computers, running non-sensitive commands |
| The assistant (AI) | Reading status; proposing a non-sensitive command, which a person confirms |
| The network | Nothing: TLS, and every request and response is signed |

## Security decisions

- **Pairing, not passwords.** The owner creates a one-time code on the dashboard (valid for
  10 minutes, single use, stored hashed). `home-helper-agent enroll --code ABCD-EFGH` trades
  it for an agent id and a 256-bit secret. No account password ever touches the agent.
- **Every request is signed** with HMAC-SHA-512 over the method, path, timestamp, a random
  nonce, the agent id and a hash of the body. The server rejects old timestamps (±5 min) and
  reused nonces, so a captured request cannot be replayed.
- **Every response is signed too**, over the status, the request's nonce and the body. A
  response cannot be forged or swapped for another one, even by something that can see into
  TLS (a corporate proxy, a misconfigured certificate).
- **The secret is protected at rest**: DPAPI on Windows plus an ACL for SYSTEM,
  Administrators and the owner; mode 0600 on Linux.
- **The allowlist lives on the computer.** Each entry is a command key, an absolute program
  path, fixed arguments and named parameters with a regular expression each. There is no
  shell: the program is started directly, parameters cannot start with `-`, and
  parameters cannot add arguments. The server only learns the list; it cannot change it.
- **Sensitive means confirm.** An entry marked `sensitive` is queued only after the household
  owner presses Confirm, and the agent refuses a sensitive command that arrives unconfirmed.
- **Updates are signed with Ed25519.** The server only relays an offer. The agent checks
  the download against a public key in its own config and installs only when the owner
  asks it to.

## Protocol

[protocol.md](protocol.md) has every endpoint, the signature format and test vectors that
the C++ and PHP tests share.
