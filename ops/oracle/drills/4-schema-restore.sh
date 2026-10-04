#!/bin/bash
# Drill 4: the HH user is dropped, with everything it owns. Bring it back from the
# Data Pump file (scripts/datapump-export.sh), without touching the rest of the database.
#   docker exec oracle bash /opt/hh/drills/4-schema-restore.sh
set -euo pipefail
dump=${1:-hh.dmp}
pdb() { bash /opt/hh/scripts/sql.sh sys; }
counts="SELECT (SELECT COUNT(*) FROM hh.expenses) AS expenses, (SELECT COUNT(*) FROM hh.expenses_archive) AS archived,
       (SELECT COUNT(*) FROM hh.food_entries) AS food, (SELECT COUNT(*) FROM hh.chore_completions) AS completions,
       (SELECT COUNT(*) FROM hh.todos) AS todos FROM dual;"

echo "== Before"
pdb <<SQL
ALTER SESSION SET CONTAINER = FREEPDB1;
$counts
SELECT object_type, COUNT(*) FROM dba_objects WHERE owner = 'HH' GROUP BY object_type ORDER BY 1;
SQL

echo "== Drop the user"
start=$(date +%s)
pdb <<'SQL'
ALTER SESSION SET CONTAINER = FREEPDB1;
DROP USER hh CASCADE;
SELECT COUNT(*) AS hh_users FROM dba_users WHERE username = 'HH';
SQL

echo "== Import it back"
par=$(mktemp)
trap 'rm -f "$par"' EXIT
chmod 600 "$par"
cat > "$par" <<EOF
userid="system/${ORACLE_PASSWORD}@//localhost:1521/FREEPDB1"
schemas=HH
directory=DATA_PUMP_DIR
dumpfile=${dump}
logfile=${dump%.dmp}-import.log
EOF
impdp parfile="$par" 2>&1 | grep -E "^(Processing|Job|ORA-)|imported" | grep -vE '"(EXPENSES|FOOD_ENTRIES|CHORE_COMPLETIONS)":"SYS_P' || true
echo "drill took $(( $(date +%s) - start )) s"

echo "== After"
pdb <<SQL
ALTER SESSION SET CONTAINER = FREEPDB1;
$counts
SELECT object_type, COUNT(*) FROM dba_objects WHERE owner = 'HH' GROUP BY object_type ORDER BY 1;
SELECT COUNT(*) AS invalid_objects FROM dba_objects WHERE owner = 'HH' AND status <> 'VALID';
SELECT username, default_tablespace, account_status FROM dba_users WHERE username = 'HH';
SQL
# The user can log in again with the same password
bash /opt/hh/scripts/sql.sh hh <<'SQL'
SELECT job_name, enabled FROM user_scheduler_jobs;
SQL
