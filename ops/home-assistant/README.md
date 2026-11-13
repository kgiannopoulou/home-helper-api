# The test home

A real Home Assistant (current stable image) with simulated devices, for development and the
end-to-end tests. Setup, the Raspberry Pi install and the hardware list: [docs/smart-home/setup.md](../../docs/smart-home/setup.md).

```bash
docker compose --profile smart-home up -d homeassistant
MSYS_NO_PATHCONV=1 docker exec homeassistant python3 /hh-scripts/onboard.py | tail -1   # a token
```

| Path | What |
|---|---|
| `config/configuration.yaml` | Home Assistant's own configuration (no discovery inside a container) |
| `config/packages/test_home.yaml` | The devices: template lights, switches, sensors, doors, a lock, backed by `sim_*` helpers |
| `scripts/onboard.py` | Onboarding without the browser: owner, rooms, a long-lived token |

Everything else Home Assistant writes into `config/` (its database, registries, tokens) is git-ignored.
Start over with `docker compose rm -sf homeassistant` and `git clean -fdX ops/home-assistant/config`.
