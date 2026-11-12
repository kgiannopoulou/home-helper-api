#!/bin/sh
# Installs home-helper-agent as a systemd service (agent/docs/service.md).
#
#   cmake -S agent -B agent/build -DCMAKE_BUILD_TYPE=Release && cmake --build agent/build
#   sudo agent/packaging/linux/install.sh --server https://home.example.org --code K7QM-2XPA
#
# Idempotent: run it again after a new build to replace the binary.
set -eu

here=$(cd "$(dirname "$0")" && pwd)
binary="$here/../../build/home-helper-agent"
server=""
code=""
while [ $# -gt 0 ]; do
    case "$1" in
        --server) server="$2"; shift 2 ;;
        --code) code="$2"; shift 2 ;;
        --binary) binary="$2"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "run it with sudo" >&2
    exit 1
fi
[ -x "$binary" ] || { echo "no binary at $binary: build it first" >&2; exit 1; }

# A system user with no shell and no home
if ! id home-helper-agent >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin home-helper-agent
fi

install -m 0755 "$binary" /usr/local/bin/home-helper-agent
install -d -m 0750 -o root -g home-helper-agent /etc/home-helper-agent
install -d -m 0700 -o home-helper-agent -g home-helper-agent /var/lib/home-helper-agent
install -m 0644 "$here/home-helper-agent.service" /etc/systemd/system/home-helper-agent.service

config=/etc/home-helper-agent/agent.json
if [ ! -f "$config" ]; then
    [ -n "$server" ] || { echo "first install: pass --server URL" >&2; exit 2; }
    /usr/local/bin/home-helper-agent --config "$config" init --server "$server"
    # The service user reads the config; only root changes it (the allowlist is in it)
    chown root:home-helper-agent "$config"
    chmod 0640 "$config"
fi

if [ -n "$code" ]; then
    # Enrolls as the service user, so the credentials file is theirs and mode 0600
    runuser -u home-helper-agent -- /usr/local/bin/home-helper-agent --config "$config" enroll --code "$code"
fi

systemctl daemon-reload
echo "Installed. Review the commands in $config, then:"
echo "  sudo systemctl enable --now home-helper-agent"
echo "  journalctl -u home-helper-agent -f"
