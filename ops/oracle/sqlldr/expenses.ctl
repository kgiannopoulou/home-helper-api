-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/expenses.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/expenses.csv'
BADFILE '/tmp/hh-load/expenses.bad'
DISCARDFILE '/tmp/hh-load/expenses.dsc'
APPEND INTO TABLE expenses
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, user_id, recurring_bill_id, entry_date DATE "YYYY-MM-DD", amount, category, source, note)
