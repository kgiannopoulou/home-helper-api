-- As HH. Partition pruning: the date range decides which partitions are read.
-- Pstart/Pstop in the plan are partition numbers (1 = p_start, 2 = January 2023, ...).

PROMPT == One month of one household's spending (the dashboard's question)
EXPLAIN PLAN FOR
SELECT category, SUM(amount) AS total
FROM expenses
WHERE household_id = (SELECT MIN(id) FROM households)
  AND entry_date >= DATE '2026-09-01' AND entry_date < DATE '2026-10-01'
GROUP BY category;
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION +PREDICATE'));

PROMPT == A quarter: three neighbouring partitions
EXPLAIN PLAN FOR
SELECT SUM(amount) FROM expenses
WHERE entry_date >= DATE '2026-07-01' AND entry_date < DATE '2026-10-01';
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION'));

PROMPT == No date at all: every partition
EXPLAIN PLAN FOR
SELECT SUM(amount) FROM expenses WHERE category = 'fun';
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION'));

PROMPT == A function on the key hides it from the optimizer: no pruning
EXPLAIN PLAN FOR
SELECT SUM(amount) FROM expenses WHERE TO_CHAR(entry_date, 'YYYY-MM') = '2026-09';
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION'));

PROMPT == food_entries: one person's September
EXPLAIN PLAN FOR
SELECT entry_date, SUM(kcal), SUM(protein) FROM food_entries
WHERE user_id = 1 AND entry_date >= DATE '2026-09-01' AND entry_date < DATE '2026-10-01'
GROUP BY entry_date;
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION'));

PROMPT == chore_completions: a TIMESTAMP key prunes the same way
EXPLAIN PLAN FOR
SELECT user_id, SUM(minutes) FROM chore_completions
WHERE done_at >= TIMESTAMP '2026-09-05 00:00:00' AND done_at < TIMESTAMP '2026-10-05 00:00:00'
GROUP BY user_id;
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION'));
EXIT
