#!/bin/bash
# Daily incremental (level 1) backup, Monday to Saturday. Schedule (host crontab):
#   0 2 * * 1-6  docker exec oracle bash /opt/hh/scripts/rman-level1.sh
set -euo pipefail
mkdir -p /tmp/rman
log=/tmp/rman/level1-$(date +%Y%m%d-%H%M).log
NLS_DATE_FORMAT="YYYY-MM-DD HH24:MI:SS" rman target / cmdfile=/opt/hh/rman/level1.rman log="$log" > /dev/null
grep -E "Starting backup|Finished backup|piece handle|RMAN-|ORA-" "$log" || true
echo "log: $log"
