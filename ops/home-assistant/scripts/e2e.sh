#!/usr/bin/env bash
# End to end: Home Helper against a real Home Assistant (day 84).
#
#   ops/home-assistant/scripts/e2e.sh
#
# Needs: the app running and reachable from Home Assistant as $APP (the webhook
# goes there), its database with the demo data, and the test home container
# (docker compose --profile smart-home up -d homeassistant). ARTISAN is how to run
# artisan for that app. Locally on Windows (Git Bash), with the app in a container
# on the sail network:
#
#   ARTISAN="docker run --rm --network home-helper-api_sail -v C:/Users/me/home-helper-api:/app \
#     -w /app -e DB_HOST=mysql -e REDIS_HOST=redis hh-php-test php artisan" \
#   APP=http://hh-web:8077 ops/home-assistant/scripts/e2e.sh
#
# What it proves (docs/smart-home/testing.md): connect with a real token, discovery
# with areas, lights/dimming/plugs through Home Assistant, door and sensor changes
# arriving by webhook, an automation round trip, the approval flow on a real lock,
# an unplugged lamp, wrong tokens and webhook secrets, Home Assistant stopped (fail
# fast, say why) and started again (heal, no fake "door opened" from the restart).
set -euo pipefail
export MSYS_NO_PATHCONV=1

ARTISAN=${ARTISAN:-php artisan}
APP=${APP:-http://hh-web:8077}
HA_URL=${HA_URL:-http://homeassistant:8123}
CONTAINER=${HA_CONTAINER:-homeassistant}
# Seconds to wait for a pushed change: 15 is plenty on Linux; a dev server on a
# Windows bind mount takes ~3 s per request, so use E2E_WAIT=60 there
WAIT=${E2E_WAIT:-15}

step() { printf '\n\033[1;34m### %s\033[0m\n' "$*" >&2; }
e2e() { $ARTISAN smart-home:e2e "$@" --url="$HA_URL" --app="$APP" --token="$TOKEN" --wait="$WAIT"; }
healthy() {
    for _ in $(seq 1 90); do
        [ "$(docker inspect -f '{{.State.Health.Status}}' "$CONTAINER" 2>/dev/null)" = healthy ] && return 0
        sleep 2
    done
    echo "Home Assistant did not become healthy" >&2; return 1
}
trap 'e2e cleanup >/dev/null 2>&1 || true; docker start "$CONTAINER" >/dev/null 2>&1 || true' EXIT

step "Home Assistant up and onboarded"
docker start "$CONTAINER" >/dev/null
healthy
TOKEN=$(docker exec "$CONTAINER" python3 /hh-scripts/onboard.py | tail -1)
[ -n "$TOKEN" ] || { echo "no token from onboard.py" >&2; exit 1; }
echo "   ok a long-lived token (${#TOKEN} characters)" >&2

step "setup"
WEBHOOK=$(e2e setup | tail -1)

step "Home Assistant forwards state changes to $APP"
docker exec "$CONTAINER" python3 /hh-scripts/onboard.py --events-url "$APP/api/smart-home/v1/states" --events-token "$WEBHOOK" >/dev/null
# The reload of rest_command/automation is asynchronous inside Home Assistant
sleep 3

step "devices"
e2e devices --webhook="$WEBHOOK"

step "offline: docker stop $CONTAINER"
docker stop "$CONTAINER" >/dev/null
e2e offline

step "recovered: docker start $CONTAINER"
docker start "$CONTAINER" >/dev/null
healthy
e2e recovered

step "cleanup"
e2e cleanup
trap - EXIT
printf '\n\033[32mEnd to end against Home Assistant: all stages passed\033[0m\n' >&2
