#!/bin/bash
# Runs the whole lab in order, from an empty database, saving each step's output in
# ops/oracle/output/ (the outputs quoted in RUNBOOK.md). Run on the host, from ops/oracle:
#   docker compose down -v && docker compose up -d   # wait for "DATABASE IS READY TO USE!"
#   bash scripts/run-lab.sh
set -uo pipefail
export MSYS_NO_PATHCONV=1   # Git Bash on Windows: don't rewrite /opt/hh paths
mkdir -p output
step() {
    local name=$1
    shift
    echo "== $name"
    local start=$(date +%s)
    "$@" > "output/$name.txt" 2>&1
    echo "   $(( $(date +%s) - start )) s, output/$name.txt"
}
ex() { docker exec oracle bash "$@"; }
sql() { docker exec oracle bash /opt/hh/scripts/sql.sh "$1" "/opt/hh/sql/$2"; }
rman_run() { docker exec -e NLS_DATE_FORMAT='YYYY-MM-DD HH24:MI:SS' oracle rman target / cmdfile="/opt/hh/rman/$1"; }

docker exec oracle mkdir -p /opt/oracle/oradata/fra
step 01-setup ex /opt/hh/scripts/setup.sh
step 10-partitions sql hh 10_partitions.sql
step 11-pruning sql hh 11_pruning.sql
step 12-drop-partition sql hh 12_drop_partition.sql
step 20-archivelog sql sys 20_archivelog.sql
step 21-exchange sql hh 21_exchange.sql
step 22-archive-job sql hh 22_archive_job.sql
step 30-rman-configure rman_run configure.rman
step 31-rman-level0 ex /opt/hh/scripts/rman-level0.sh
step 32-add-data sql hh 30_add_data.sql
step 33-rman-level1 ex /opt/hh/scripts/rman-level1.sh
step 34-rman-check rman_run check.rman
step 35-datapump-export ex /opt/hh/scripts/datapump-export.sh
step 41-drill-dropped-table ex /opt/hh/drills/1-dropped-table.sh
step 42-drill-bad-data-pitr ex /opt/hh/drills/2-bad-data-pitr.sh
step 43-rman-level0-after-pitr ex /opt/hh/scripts/rman-level0.sh
step 44-drill-lost-datafile ex /opt/hh/drills/3-lost-datafile.sh
step 45-drill-schema-restore ex /opt/hh/drills/4-schema-restore.sh
