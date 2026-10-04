#!/bin/bash
# Creates the hh schema in FREEPDB1 and loads the CSV data from ops/oracle/data
# (php artisan oracle:generate). Run inside the container:
#   docker exec oracle bash /opt/hh/scripts/setup.sh
set -euo pipefail
cd /opt/hh
HH="hh/${HH_PASSWORD}@//localhost:1521/FREEPDB1"

echo "== user and tablespace"
sqlplus -s / as sysdba @sql/01_user.sql "$HH_PASSWORD"

echo "== schema"
sqlplus -s "$HH" @sql/02_schema.sql

echo "== load (SQL*Loader, conventional path so every constraint is checked)"
mkdir -p /tmp/hh-load
for t in households users household_users recurring_bills rooms chores expenses food_entries chore_completions todos; do
    start=$(date +%s%N)
    sqlldr userid="$HH" control="sqlldr/$t.ctl" log="/tmp/hh-load/$t.log" silent=header,feedback || true
    loaded=$(grep -oE '[0-9]+ Rows? successfully loaded' "/tmp/hh-load/$t.log" | grep -oE '^[0-9]+')
    rejected=$(grep -oE 'Total logical records rejected: +[0-9]+' "/tmp/hh-load/$t.log" | grep -oE '[0-9]+$')
    ms=$(( ($(date +%s%N) - start) / 1000000 ))
    printf '%-18s %8s loaded, %s rejected, %6d ms\n' "$t" "$loaded" "$rejected" "$ms"
done

echo "== statistics"
sqlplus -s "$HH" <<'SQL'
EXEC DBMS_STATS.GATHER_SCHEMA_STATS('HH')
SELECT table_name, num_rows FROM user_tables WHERE table_name NOT LIKE 'EXPENSES_ARCHIVE%' ORDER BY num_rows DESC;
SQL
