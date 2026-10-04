#!/bin/bash
# Moves every month of expenses older than 24 months to EXPENSES_ARCHIVE.
# The database runs it on its own (DBMS_SCHEDULER job ARCHIVE_EXPENSES_MONTHLY,
# 1st of the month at 03:00); this is for running it by hand or from cron instead:
#   0 3 1 * *  docker exec oracle bash /opt/hh/scripts/archive-month.sh
set -euo pipefail
KEEP_MONTHS=${1:-24}
bash /opt/hh/scripts/sql.sh hh <<SQL
SET SERVEROUTPUT ON TIMING ON
EXEC archive_expenses(${KEEP_MONTHS})
EXIT
SQL
