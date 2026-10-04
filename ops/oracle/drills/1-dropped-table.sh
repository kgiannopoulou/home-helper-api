#!/bin/bash
# Drill 1: a dropped table, brought back from the recycle bin.
#   docker exec oracle bash /opt/hh/drills/1-dropped-table.sh
set -euo pipefail
start=$(date +%s%N)
bash /opt/hh/scripts/sql.sh hh <<'SQL'
SET TIMING ON
COLUMN constraint_name FORMAT A32
PROMPT == Before
SELECT constraint_name, constraint_type, status FROM user_constraints WHERE table_name = 'TODOS' ORDER BY constraint_name;
SELECT COUNT(*) AS todos, COUNT(done_at) AS done FROM todos;

DROP TABLE todos;
PROMPT == Dropped: the table is in the recycle bin, under a BIN$ name
SELECT COUNT(*) FROM todos;
COLUMN original_name FORMAT A24
COLUMN object_name FORMAT A32
SELECT original_name, object_name, type, droptime FROM user_recyclebin ORDER BY type DESC;

FLASHBACK TABLE todos TO BEFORE DROP;
PROMPT == Back: the rows, and the indexes (which keep their BIN$ names)
SELECT COUNT(*) AS todos, COUNT(done_at) AS done FROM todos;
SELECT index_name, status FROM user_indexes WHERE table_name = 'TODOS' ORDER BY index_name;
SELECT constraint_name, constraint_type, status FROM user_constraints WHERE table_name = 'TODOS' ORDER BY constraint_name;
SQL

# Give the indexes and constraints their names back
bash /opt/hh/scripts/sql.sh hh <<'SQL'
SET SERVEROUTPUT ON
COLUMN constraint_name FORMAT A32
DECLARE
  PROCEDURE rename_index(p_column VARCHAR2, p_name VARCHAR2) IS
    v_old VARCHAR2(128);
  BEGIN
    SELECT index_name INTO v_old FROM user_ind_columns
    WHERE table_name = 'TODOS' AND column_name = p_column AND column_position = 1;
    EXECUTE IMMEDIATE 'ALTER INDEX "' || v_old || '" RENAME TO ' || p_name;
  END;
BEGIN
  rename_index('ID', 'TODOS_PK');
  rename_index('HOUSEHOLD_ID', 'TODOS_HOUSEHOLD_DUE_IX');
  FOR c IN (SELECT constraint_name FROM user_constraints
            WHERE table_name = 'TODOS' AND constraint_name LIKE 'BIN$%' AND constraint_type = 'P') LOOP
    EXECUTE IMMEDIATE 'ALTER TABLE todos RENAME CONSTRAINT "' || c.constraint_name || '" TO TODOS_PK';
  END LOOP;
END;
/
-- Flashback Drop doesn't bring foreign keys back: add it again
ALTER TABLE todos ADD CONSTRAINT todos_household_fk FOREIGN KEY (household_id) REFERENCES households ON DELETE CASCADE;
SELECT index_name, status FROM user_indexes WHERE table_name = 'TODOS' ORDER BY index_name;
SELECT constraint_name, constraint_type, status FROM user_constraints WHERE table_name = 'TODOS' ORDER BY constraint_name;
SQL
echo "drill took $(( ($(date +%s%N) - start) / 1000000 )) ms"
