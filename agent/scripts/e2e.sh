#!/usr/bin/env bash
# End to end: the real C++ agent against the real Laravel app (day 70).
#
#   AGENT_BIN=agent/build/home-helper-agent SERVER=http://127.0.0.1:8077 agent/scripts/e2e.sh
#
# The app must be running at $SERVER with the demo data (demo@homehelper.test owns
# a household). ARTISAN is how to run artisan for that app (default: php artisan).
# CI runs this in the agent workflow; locally it works from Git Bash on Windows too.
#
# What it proves, step by step: pairing and enrollment, signed requests and
# responses, the inventory with the allowlist, a command round trip, a sensitive
# command held until the owner confirms it, the agent refusing a command that is
# no longer on its allowlist even though the server asked, events, and removal
# stopping the agent with exit code 3.
set -euo pipefail

AGENT_BIN=${AGENT_BIN:?set AGENT_BIN to the built agent}
SERVER=${SERVER:-http://127.0.0.1:8077}
ARTISAN=${ARTISAN:-php artisan}
WORK=$(mktemp -d)
NAME="E2E $(date +%s)"
trap 'rm -rf "$WORK"' EXIT

step() { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }
ok() { printf '\033[32m   ok\033[0m %s\n' "$*"; }
fail() { printf '\033[31m   FAILED\033[0m %s\n' "$*"; exit 1; }
agent() { "$AGENT_BIN" --config "$CONFIG" "$@"; }
artisan() { $ARTISAN "$@"; }
# A value from `agent:admin show` JSON, by a PHP expression on $j
show() { artisan agent:admin show "$NAME" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo '"$1"';'; }

case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*)
        PING='C:/Windows/System32/PING.EXE'; COUNT='-n'; HARMLESS='C:/Windows/System32/hostname.exe'
        DATA=$(cygpath -m "$WORK/data"); CONFIG=$(cygpath -m "$WORK/agent.json") ;;
    *)
        PING=$(command -v ping); COUNT='-c'; HARMLESS=$(command -v hostname)
        DATA="$WORK/data"; CONFIG="$WORK/agent.json" ;;
esac

write_config() {  # $1 = include the command that is removed later (yes/no)
    local extra=""
    if [ "$1" = yes ]; then
        extra=",{\"key\":\"soon_removed\",\"description\":\"Removed from the allowlist during the test\",\"program\":\"$HARMLESS\"}"
    fi
    cat > "$WORK/agent.json" <<JSON
{
  "server_url": "$SERVER",
  "name": "$NAME",
  "data_dir": "$DATA",
  "log": { "level": "info", "file": false },
  "commands": [
    { "key": "ping_local", "description": "Ping this computer once", "program": "$PING",
      "args": ["$COUNT", "1", "{host}"], "params": { "host": { "pattern": "^127\\\\.0\\\\.0\\\\.[0-9]{1,3}$" } },
      "timeout_seconds": 20 },
    { "key": "say_hostname", "description": "Print the hostname (sensitive, for the test)", "program": "$HARMLESS",
      "sensitive": true }$extra
  ]
}
JSON
}

step "Pair: a one-time code from the server"
CODE=$(artisan agent:pair --name "$NAME" 2>/dev/null | tail -n 1 | tr -d '\r')
[[ "$CODE" =~ ^[A-Z0-9]{4}-[A-Z0-9]{4}$ ]] || fail "no pairing code: $CODE"
ok "code $CODE"

step "Enroll the agent and check the credentials end to end"
write_config yes
agent check-config >/dev/null
agent enroll --code "$CODE"
agent status | tee "$WORK/status.txt"
grep -q "server says  OK" "$WORK/status.txt" || fail "the server did not accept the signed request"
if agent enroll --code "$CODE" --force 2>/dev/null; then fail "a pairing code worked twice"; fi
ok "enrolled; the code can't be used again"

step "First heartbeat: readings, inventory with the allowlist, events"
agent run --once
[ "$(show '$j["online"] ? "yes" : "no"')" = yes ] || fail "not online"
[ "$(show 'count($j["inventory"]["commands"])')" = 3 ] || fail "the inventory does not list the 3 commands"
[ "$(show 'isset($j["metrics"]["memory"]) ? "yes" : "no"')" = yes ] || fail "no readings"
show 'implode(",", array_column($j["events"], "type"))' | grep -q agent.started || fail "no agent.started event"
ok "online, $(show 'count($j["inventory"]["disks"])') disk(s), $(show 'count($j["inventory"]["audio"])') audio device(s), 3 commands"

step "A command: queued on the server, run by the agent, result back"
artisan agent:admin run "$NAME" ping_local host=127.0.0.1
agent run --once
[ "$(show '$j["commands"][0]["status"]')" = succeeded ] || fail "ping_local: $(show 'json_encode($j["commands"][0])')"
show '$j["commands"][0]["output"]' | grep -q 127.0.0.1 || fail "no ping output"
ok "ping_local succeeded, exit $(show '$j["commands"][0]["exit_code"]')"

step "The server refuses a value that doesn't match the pattern"
if artisan agent:admin run "$NAME" ping_local "host=127.0.0.1; whoami" 2>/dev/null; then fail "an injected value was queued"; fi
ok "refused before it reached the computer"

step "A sensitive command waits for the owner"
artisan agent:admin run "$NAME" say_hostname
agent run --once
[ "$(show '$j["commands"][0]["status"]')" = awaiting_confirmation ] || fail "sent before confirmation"
artisan agent:admin confirm "$(show '$j["commands"][0]["id"]')"
agent run --once
[ "$(show '$j["commands"][0]["status"]')" = succeeded ] || fail "say_hostname: $(show 'json_encode($j["commands"][0])')"
ok "held, confirmed by the owner, then run"

step "The agent has the last word: a command removed from its allowlist is refused"
artisan agent:admin run "$NAME" soon_removed
write_config no
agent run --once
[ "$(show '$j["commands"][0]["status"]')" = rejected ] || fail "soon_removed: $(show 'json_encode($j["commands"][0])')"
show '$j["commands"][0]["reason"]' | grep -q "not on this computer's allowlist" || fail "wrong reason"
show 'implode(",", array_column($j["events"], "type"))' | grep -q command.rejected || fail "no command.rejected event"
[ "$(show 'count($j["inventory"]["commands"])')" = 2 ] || fail "the inventory still lists the removed command"
ok "rejected by the agent, reported as an event, and the inventory updated"

step "Removed on the server: the agent stops for good (exit 3)"
artisan agent:admin revoke "$NAME"
set +e
agent run
code=$?
set -e
[ "$code" = 3 ] || fail "exit code $code, expected 3"
ok "stopped with exit code 3"

printf '\n\033[1;32mEnd to end: all steps passed.\033[0m\n'
