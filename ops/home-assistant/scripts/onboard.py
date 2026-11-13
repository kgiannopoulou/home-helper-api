"""Onboard a fresh Home Assistant and hand Home Helper a token.

Runs *inside* the Home Assistant container, which already has Python and
aiohttp:

    docker exec homeassistant python3 /hh-scripts/onboard.py

What it does, each step skipped if already done:

  1. creates the owner account (the onboarding wizard's first page)
  2. finishes the other onboarding steps (location, analytics, integrations)
  3. creates the rooms as Home Assistant areas and puts each test device in its room
  4. creates a long-lived access token named "Home Helper"
  5. with --events-url and --events-token: writes secrets.yaml so the
     forwarding automation (packages/home_helper.yaml) reaches Home Helper,
     and reloads it

    docker exec homeassistant python3 /hh-scripts/onboard.py \n        --events-url http://hh-web:8077/api/smart-home/v1/states --events-token 01J....xyz

It prints the token on the last line, and nothing else goes to stdout, so
scripts can do TOKEN=$(docker exec ... | tail -1). Never commit the token.
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys

import aiohttp

BASE = os.environ.get("HA_URL", "http://127.0.0.1:8123")
CLIENT_ID = BASE + "/"
USER = os.environ.get("HA_USER", "owner")
PASSWORD = os.environ.get("HA_PASSWORD", "home-helper-test")

# Home Assistant area -> the test devices in it (packages/test_home.yaml)
ROOMS = {
    "Living room": ["light.living_room_lamp", "sensor.living_room_temperature", "sensor.living_room_humidity", "binary_sensor.balcony_door"],
    "Kitchen": ["light.kitchen_ceiling", "switch.coffee_machine_plug"],
    "Bathroom": ["switch.dehumidifier_plug", "sensor.bathroom_temperature", "sensor.bathroom_humidity"],
    "Bedroom": ["light.bedroom_lamp", "sensor.bedroom_temperature", "sensor.bedroom_humidity", "binary_sensor.bedroom_window"],
    "Hallway": ["switch.hallway_light", "binary_sensor.front_door", "binary_sensor.hallway_motion", "lock.front_door_lock"],
}


def log(message: str) -> None:
    print(message, file=sys.stderr, flush=True)


async def wait_until_up(session: aiohttp.ClientSession) -> None:
    for _ in range(120):
        try:
            async with session.get(BASE + "/manifest.json") as r:
                if r.status == 200:
                    return
        except aiohttp.ClientError:
            pass
        await asyncio.sleep(1)
    raise SystemExit("Home Assistant did not start within two minutes")


async def token_from_code(session: aiohttp.ClientSession, code: str) -> str:
    async with session.post(BASE + "/auth/token", data={"grant_type": "authorization_code", "code": code, "client_id": CLIENT_ID}) as r:
        r.raise_for_status()
        return (await r.json())["access_token"]


async def log_in(session: aiohttp.ClientSession) -> str:
    """The login form, for a Home Assistant that is already onboarded"""
    async with session.post(BASE + "/auth/login_flow", json={"client_id": CLIENT_ID, "handler": ["homeassistant", None], "redirect_uri": CLIENT_ID}) as r:
        r.raise_for_status()
        flow = await r.json()
    async with session.post(f"{BASE}/auth/login_flow/{flow['flow_id']}", json={"client_id": CLIENT_ID, "username": USER, "password": PASSWORD}) as r:
        r.raise_for_status()
        result = await r.json()
    if result.get("type") != "create_entry":
        raise SystemExit("Could not log in as " + USER + ": " + json.dumps(result.get("errors")))
    return await token_from_code(session, result["result"])


async def onboard(session: aiohttp.ClientSession) -> str:
    async with session.get(BASE + "/api/onboarding") as r:
        steps = {s["step"]: s["done"] for s in await r.json()} if r.status == 200 else {}

    if steps and not steps.get("user"):
        log("Creating the owner account")
        body = {"client_id": CLIENT_ID, "name": "Owner", "username": USER, "password": PASSWORD, "language": "en"}
        async with session.post(BASE + "/api/onboarding/users", json=body) as r:
            r.raise_for_status()
            access = await token_from_code(session, (await r.json())["auth_code"])
    else:
        access = await log_in(session)

    headers = {"Authorization": "Bearer " + access}
    for step, body in (("core_config", {}), ("analytics", {}), ("integration", {"client_id": CLIENT_ID, "redirect_uri": CLIENT_ID})):
        if steps and not steps.get(step):
            async with session.post(f"{BASE}/api/onboarding/{step}", json=body, headers=headers) as r:
                log(f"Onboarding step {step}: HTTP {r.status}")
    return access


class Socket:
    """Home Assistant's WebSocket API: areas, the entity registry and tokens live there"""

    def __init__(self, ws: aiohttp.ClientWebSocketResponse) -> None:
        self.ws = ws
        self.next_id = 1

    async def call(self, message: dict) -> dict | list | None:
        message = {"id": self.next_id, **message}
        self.next_id += 1
        await self.ws.send_json(message)
        while True:
            reply = await self.ws.receive_json()
            if reply.get("id") == message["id"]:
                if not reply.get("success"):
                    raise RuntimeError(f"{message['type']}: {reply.get('error')}")
                return reply.get("result")


def write_secrets(url: str, token: str) -> None:
    with open("/config/secrets.yaml", "w", encoding="utf-8") as f:
        f.write("# Written by /hh-scripts/onboard.py; git-ignored\n")
        f.write(f"home_helper_events_url: {json.dumps(url)}\n")
        f.write(f"home_helper_events_auth: {json.dumps('Bearer ' + token)}\n")
    os.chmod("/config/secrets.yaml", 0o600)


async def reload_forwarding(session: aiohttp.ClientSession, access: str) -> None:
    headers = {"Authorization": "Bearer " + access}
    for service in ("rest_command/reload", "automation/reload"):
        async with session.post(f"{BASE}/api/services/{service}", json={}, headers=headers) as r:
            log(f"Reloaded {service.split('/')[0]}: HTTP {r.status}")


async def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--events-url")
    parser.add_argument("--events-token")
    args = parser.parse_args()

    async with aiohttp.ClientSession() as session:
        await wait_until_up(session)
        access = await onboard(session)
        if args.events_url and args.events_token:
            write_secrets(args.events_url, args.events_token)
            await reload_forwarding(session, access)

        async with session.ws_connect(BASE.replace("http", "ws", 1) + "/api/websocket") as ws:
            await ws.receive_json()  # auth_required
            await ws.send_json({"type": "auth", "access_token": access})
            if (await ws.receive_json()).get("type") != "auth_ok":
                raise SystemExit("WebSocket authentication failed")
            sock = Socket(ws)

            # Onboarding already made a few ("Living Room", "Kitchen", "Bedroom"): match without case or spaces
            def key(name: str) -> str:
                return name.lower().replace(" ", "")

            areas = {key(a["name"]): a["area_id"] for a in await sock.call({"type": "config/area_registry/list"})}
            entities = {e["entity_id"] for e in await sock.call({"type": "config/entity_registry/list"})}
            for room, members in ROOMS.items():
                if key(room) not in areas:
                    areas[key(room)] = (await sock.call({"type": "config/area_registry/create", "name": room}))["area_id"]
                    log("Created area " + room)
                for entity_id in members:
                    if entity_id in entities:
                        await sock.call({"type": "config/entity_registry/update", "entity_id": entity_id, "area_id": areas[key(room)]})
                    else:
                        log("Not in the registry (check packages/test_home.yaml): " + entity_id)

            token = await sock.call({"type": "auth/long_lived_access_token", "client_name": "Home Helper " + os.urandom(3).hex(), "lifespan": 3650})

    print(token)


if __name__ == "__main__":
    asyncio.run(main())
