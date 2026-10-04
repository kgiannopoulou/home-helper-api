-- As HH. The monthly job: every month older than p_keep_months goes to the archive,
-- one partition at a time, the way 21_exchange.sql did March 2023.
SET SERVEROUTPUT ON SIZE UNLIMITED

CREATE OR REPLACE PROCEDURE archive_expenses(p_keep_months IN PLS_INTEGER DEFAULT 24) AS
  v_cutoff  DATE := TRUNC(ADD_MONTHS(SYSDATE, -p_keep_months), 'MM');
  v_month   DATE;
  v_rows    PLS_INTEGER;
  v_start   TIMESTAMP;
  v_for     VARCHAR2(40);
BEGIN
  LOOP
    SELECT TRUNC(MIN(entry_date), 'MM') INTO v_month FROM expenses;
    EXIT WHEN v_month IS NULL OR v_month >= v_cutoff;

    v_start := SYSTIMESTAMP;
    v_for := 'FOR (DATE ''' || TO_CHAR(v_month, 'YYYY-MM-DD') || ''')';
    -- Swap the month's partition with the empty stage table: no rows are copied
    EXECUTE IMMEDIATE 'ALTER TABLE expenses EXCHANGE PARTITION ' || v_for
      || ' WITH TABLE expenses_archive_stage UPDATE GLOBAL INDEXES';
    INSERT /*+ APPEND */ INTO expenses_archive (id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note)
      SELECT id, household_id, user_id, recurring_bill_id, entry_date, amount, category, source, note FROM expenses_archive_stage;
    v_rows := SQL%ROWCOUNT;
    COMMIT;
    EXECUTE IMMEDIATE 'TRUNCATE TABLE expenses_archive_stage';
    EXECUTE IMMEDIATE 'ALTER TABLE expenses DROP PARTITION ' || v_for || ' UPDATE GLOBAL INDEXES';

    DBMS_OUTPUT.PUT_LINE(TO_CHAR(v_month, 'YYYY-MM') || ': ' || v_rows || ' rows archived in '
      || EXTRACT(SECOND FROM (SYSTIMESTAMP - v_start)) || ' s');
  END LOOP;
END archive_expenses;
/
SHOW ERRORS

-- Run on the 1st of every month at 03:00, inside the database
BEGIN
  DBMS_SCHEDULER.CREATE_JOB(
    job_name        => 'ARCHIVE_EXPENSES_MONTHLY',
    job_type        => 'STORED_PROCEDURE',
    job_action      => 'ARCHIVE_EXPENSES',
    start_date      => SYSTIMESTAMP,
    repeat_interval => 'FREQ=MONTHLY; BYMONTHDAY=1; BYHOUR=3; BYMINUTE=0',
    enabled         => TRUE,
    comments        => 'Moves expenses older than 24 months to EXPENSES_ARCHIVE');
END;
/
COLUMN job_name FORMAT A26
COLUMN repeat_interval FORMAT A48
COLUMN next_run_date FORMAT A36
SELECT job_name, repeat_interval, next_run_date, enabled FROM user_scheduler_jobs;

-- And once now, for everything that's already older than 24 months
SET TIMING ON
EXEC archive_expenses
SELECT TO_CHAR(MIN(entry_date), 'YYYY-MM-DD') AS oldest_in_expenses, COUNT(*) AS rows_left FROM expenses;
SELECT COUNT(*) AS rows_archived, TO_CHAR(MAX(entry_date), 'YYYY-MM-DD') AS newest_archived FROM expenses_archive;
EXIT
