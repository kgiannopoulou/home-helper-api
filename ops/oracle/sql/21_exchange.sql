-- As HH. Moves March 2023 (older than 2 years) out of expenses by swapping its
-- partition with the empty staging table, then into the archive.
SET TIMING ON

PROMPT == Before: the partition and the empty staging table
SELECT COUNT(*) AS march_rows FROM expenses PARTITION FOR (DATE '2023-03-01');
SELECT COUNT(*) AS stage_rows FROM expenses_archive_stage;
-- The segment (data object id) March's rows are stored in, and the staging table's
SELECT 'march partition' AS what, DBMS_ROWID.ROWID_OBJECT(MIN(ROWID)) AS data_object_id
FROM expenses PARTITION FOR (DATE '2023-03-01')
UNION ALL
SELECT 'stage table', data_object_id FROM user_objects WHERE object_name = 'EXPENSES_ARCHIVE_STAGE';

PROMPT == The exchange: a dictionary update, the rows don't move
ALTER TABLE expenses
  EXCHANGE PARTITION FOR (DATE '2023-03-01')
  WITH TABLE expenses_archive_stage
  UPDATE GLOBAL INDEXES;

PROMPT == After: the rows are in the staging table, the partition is empty
SELECT COUNT(*) AS march_rows FROM expenses PARTITION FOR (DATE '2023-03-01');
SELECT COUNT(*) AS stage_rows FROM expenses_archive_stage;
-- The stage table now owns March's old segment: same data object id as before
SELECT 'stage table' AS what, data_object_id FROM user_objects WHERE object_name = 'EXPENSES_ARCHIVE_STAGE';

PROMPT == Into the archive, then empty the stage and drop the empty partition
INSERT /*+ APPEND */ INTO expenses_archive (id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note)
SELECT id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note FROM expenses_archive_stage;
COMMIT;
TRUNCATE TABLE expenses_archive_stage;
ALTER TABLE expenses DROP PARTITION FOR (DATE '2023-03-01') UPDATE GLOBAL INDEXES;

SELECT TO_CHAR(MIN(entry_date), 'YYYY-MM-DD') AS oldest_in_expenses FROM expenses;
SELECT TO_CHAR(entry_date, 'YYYY-MM') AS month, COUNT(*) AS rows_archived
FROM expenses_archive GROUP BY TO_CHAR(entry_date, 'YYYY-MM') ORDER BY 1;
SELECT index_name, status FROM user_indexes WHERE table_name = 'EXPENSES';
EXIT
