-- Read by every sqlplus that scripts/sql.sh starts (ORACLE_PATH=/opt/hh/sql):
-- column widths, so the output pasted in RUNBOOK.md stays readable.
SET LINESIZE 160 PAGESIZE 200 TRIMSPOOL ON TAB OFF FEEDBACK OFF LONG 80
ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS';
SET FEEDBACK ON
COLUMN account_status FORMAT A14
COLUMN constraint_name FORMAT A32
COLUMN default_tablespace FORMAT A18
COLUMN file_name FORMAT A55
COLUMN high_value FORMAT A84
COLUMN index_name FORMAT A28
COLUMN interval FORMAT A30
COLUMN job_name FORMAT A26
COLUMN name FORMAT A60
COLUMN object_type FORMAT A16
COLUMN partition_name FORMAT A16
COLUMN status FORMAT A9
COLUMN table_name FORMAT A22
COLUMN tablespace_name FORMAT A14
COLUMN username FORMAT A10
COLUMN value FORMAT A40
COLUMN what FORMAT A16
