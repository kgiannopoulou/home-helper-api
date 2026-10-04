#!/bin/bash
# Drill 3: the HH_DATA datafile is deleted from disk. Restore and recover just that
# file from the backups and the archived logs, while the rest of the database stays open.
#   docker exec oracle bash /opt/hh/drills/3-lost-datafile.sh
set -euo pipefail
export NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS'
pdb() { bash /opt/hh/scripts/sql.sh sys; }

echo "== The tablespace's file"
read -r file_no file_name < <(echo "SET HEADING OFF FEEDBACK OFF
ALTER SESSION SET CONTAINER = FREEPDB1;
SELECT file_id || ' ' || file_name FROM dba_data_files WHERE tablespace_name = 'HH_DATA';" | pdb | grep -E '^ *[0-9]+ /')
echo "file $file_no: $file_name"
pdb <<SQL
ALTER SESSION SET CONTAINER = FREEPDB1;
SELECT file_id, file_name, ROUND(bytes / 1024 / 1024) AS mb, status, online_status
FROM dba_data_files WHERE tablespace_name = 'HH_DATA';
SQL
bash /opt/hh/scripts/sql.sh hh <<'SQL'
SELECT COUNT(*) AS expenses, SUM(amount) AS total FROM expenses;
SQL

echo "== Lose it: offline, then deleted"
start=$(date +%s)
pdb <<SQL
ALTER SESSION SET CONTAINER = FREEPDB1;
ALTER DATABASE DATAFILE ${file_no} OFFLINE;
SQL
rm -v "$file_name"
bash /opt/hh/scripts/sql.sh hh <<'SQL'
SELECT COUNT(*) FROM expenses;
SQL

echo "== Restore and recover file $file_no"
rman target / <<RMAN
RESTORE DATAFILE ${file_no};
RECOVER DATAFILE ${file_no};
RMAN
pdb <<SQL
ALTER SESSION SET CONTAINER = FREEPDB1;
ALTER DATABASE DATAFILE ${file_no} ONLINE;
SELECT file_id, status, online_status FROM dba_data_files WHERE tablespace_name = 'HH_DATA';
SQL
echo "drill took $(( $(date +%s) - start )) s"

echo "== After"
ls -l "$file_name"
bash /opt/hh/scripts/sql.sh hh <<'SQL'
SELECT COUNT(*) AS expenses, SUM(amount) AS total FROM expenses;
SELECT COUNT(*) AS food_entries FROM food_entries;
SQL
