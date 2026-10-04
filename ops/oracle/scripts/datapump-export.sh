#!/bin/bash
# Logical backup of the HH schema with Data Pump (expdp), into DATA_PUMP_DIR.
#   docker exec oracle bash /opt/hh/scripts/datapump-export.sh
#
# Exported by SYSTEM, not by HH itself: only a privileged export (the
# DATAPUMP_EXP_FULL_DATABASE role) carries the CREATE USER, its grants and quota,
# so impdp can bring the schema back after DROP USER hh CASCADE.
# The password goes through a parameter file, not the command line (ps shows that).
set -euo pipefail
dump=${1:-hh.dmp}
par=$(mktemp)
trap 'rm -f "$par"' EXIT
chmod 600 "$par"
cat > "$par" <<EOF
userid="system/${ORACLE_PASSWORD}@//localhost:1521/FREEPDB1"
schemas=HH
directory=DATA_PUMP_DIR
dumpfile=${dump}
logfile=${dump%.dmp}-export.log
reuse_dumpfiles=YES
EOF
expdp parfile="$par"
