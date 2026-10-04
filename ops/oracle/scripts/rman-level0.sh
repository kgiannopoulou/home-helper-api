#!/bin/bash
# Weekly full (level 0) backup. Schedule (host crontab):
#   0 2 * * 0    docker exec oracle bash /opt/hh/scripts/rman-level0.sh
set -euo pipefail
mkdir -p /tmp/rman
log=/tmp/rman/level0-$(date +%Y%m%d-%H%M).log
NLS_DATE_FORMAT="YYYY-MM-DD HH24:MI:SS" rman target / cmdfile=/opt/hh/rman/level0.rman log="$log" > /dev/null
grep -E "Starting backup|Finished backup|piece handle|RMAN-|ORA-" "$log" || true
echo "log: $log"
