-- As HH. What happens to a LOCAL and a GLOBAL index when a partition is dropped.
-- January and February 2023 are copied to the archive first, the slow way
-- (INSERT ... SELECT); March goes there the fast way, by EXCHANGE (20_exchange.sql).
SET TIMING ON

PROMPT == Before
SELECT index_name, status FROM user_indexes WHERE index_name = 'EXPENSES_ID_GIX';
SELECT COUNT(*) AS local_index_partitions FROM user_ind_partitions WHERE index_name = 'EXPENSES_HOUSEHOLD_DATE_LIX';

PROMPT == January 2023: copy, then DROP PARTITION with no index clause
INSERT INTO expenses_archive (id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note)
SELECT id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note
FROM expenses PARTITION FOR (DATE '2023-01-01');
COMMIT;
ALTER TABLE expenses DROP PARTITION FOR (DATE '2023-01-01');

SELECT index_name, status FROM user_indexes WHERE index_name = 'EXPENSES_ID_GIX';
SELECT COUNT(*) AS local_index_partitions FROM user_ind_partitions WHERE index_name = 'EXPENSES_HOUSEHOLD_DATE_LIX';

PROMPT == An UNUSABLE primary key index: a lookup by id can't use it, and inserts fail
SELECT COUNT(*) FROM expenses WHERE id = 'x';
INSERT INTO expenses (id, household_id, entry_date, amount, category)
SELECT '01zzzzzzzzzzzzzzzzzzzzzzzz', MIN(id), DATE '2026-10-04', 1, 'other' FROM households;
ROLLBACK;

PROMPT == Rebuild it: reads every row of every partition
ALTER INDEX expenses_id_gix REBUILD ONLINE;
SELECT index_name, status FROM user_indexes WHERE index_name = 'EXPENSES_ID_GIX';

PROMPT == February 2023: DROP PARTITION ... UPDATE GLOBAL INDEXES
INSERT INTO expenses_archive (id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note)
SELECT id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note
FROM expenses PARTITION FOR (DATE '2023-02-01');
COMMIT;
ALTER TABLE expenses DROP PARTITION FOR (DATE '2023-02-01') UPDATE GLOBAL INDEXES;

SELECT i.index_name, i.status, i.orphaned_entries
FROM user_indexes i WHERE i.index_name = 'EXPENSES_ID_GIX';
SELECT COUNT(*) AS local_index_partitions FROM user_ind_partitions WHERE index_name = 'EXPENSES_HOUSEHOLD_DATE_LIX';
SELECT COUNT(*) AS archived FROM expenses_archive;
EXIT
