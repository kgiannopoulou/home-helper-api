#!/bin/bash
# Runs SQL*Plus as HH in FREEPDB1 (default) or as SYSDBA, with sql/login.sql's formatting.
#   docker exec -i oracle bash /opt/hh/scripts/sql.sh hh  /opt/hh/sql/10_partitions.sql
#   docker exec -i oracle bash /opt/hh/scripts/sql.sh sys < some.sql
set -euo pipefail
export ORACLE_PATH=/opt/hh/sql

who=${1:-hh}
shift || true
case "$who" in
    hh) conn="hh/${HH_PASSWORD}@//localhost:1521/FREEPDB1" ;;
    sys) conn="/ as sysdba" ;;
    *) echo "usage: sql.sh hh|sys [file.sql [args]]" >&2; exit 2 ;;
esac

if [ $# -gt 0 ]; then
    sqlplus -s -L "$conn" @"$@"
else
    sqlplus -s -L "$conn"
fi
