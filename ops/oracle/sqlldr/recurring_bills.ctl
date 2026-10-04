-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/recurring_bills.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/recurring_bills.csv'
BADFILE '/tmp/hh-load/recurring_bills.bad'
DISCARDFILE '/tmp/hh-load/recurring_bills.dsc'
APPEND INTO TABLE recurring_bills
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, name, amount, category, day_of_month)
