#!/bin/bash
# Drill 2: a bad UPDATE was committed. Put the pluggable database back to the moment
# before it with RMAN (point-in-time recovery of FREEPDB1 only; the CDB stays up).
#   docker exec oracle bash /opt/hh/drills/2-bad-data-pitr.sh
set -euo pipefail
export NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS'
hh() { bash /opt/hh/scripts/sql.sh hh; }

echo "== Before"
hh <<'SQL'
SELECT COUNT(*) AS expenses, SUM(amount) AS total FROM expenses;
SQL

# The last good moment, by the database's clock
good=$(echo "SET HEADING OFF FEEDBACK OFF
SELECT TO_CHAR(SYSDATE, 'YYYY-MM-DD HH24:MI:SS') FROM dual;" | hh | tr -d '[:space:]' | sed 's/^\(.\{10\}\)/\1 /')
echo "last good moment: $good"
sleep 5

echo "== The mistake"
hh <<'SQL'
-- What the lab asks for first: the CHECK constraint (amount > 0) refuses it
UPDATE expenses SET amount = 0;
-- So the mistake has to be one the database allows
UPDATE expenses SET amount = 0.01;
COMMIT;
SELECT COUNT(*) AS expenses, SUM(amount) AS total FROM expenses;
SQL

echo "== Recover FREEPDB1 to $good"
start=$(date +%s)
rman target / <<RMAN
ALTER PLUGGABLE DATABASE freepdb1 CLOSE IMMEDIATE;
RUN {
  SET UNTIL TIME "TO_DATE('${good}', 'YYYY-MM-DD HH24:MI:SS')";
  RESTORE PLUGGABLE DATABASE freepdb1;
  RECOVER PLUGGABLE DATABASE freepdb1;
}
ALTER PLUGGABLE DATABASE freepdb1 OPEN RESETLOGS;
RMAN
echo "recovery took $(( $(date +%s) - start )) s"

echo "== After: the totals from before the mistake"
hh <<'SQL'
SELECT COUNT(*) AS expenses, SUM(amount) AS total FROM expenses;
SELECT COUNT(*) AS food_entries FROM food_entries;
SELECT job_name, enabled FROM user_scheduler_jobs;
SQL
bash /opt/hh/scripts/sql.sh sys <<'SQL'
COLUMN name FORMAT A12
SELECT name, open_mode FROM v$pdbs;
SELECT db_incarnation#, pdb_incarnation#, status, incarnation_time FROM v$pdb_incarnation ORDER BY 1, 2;
SQL
