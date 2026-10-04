-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/household_users.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/household_users.csv'
BADFILE '/tmp/hh-load/household_users.bad'
DISCARDFILE '/tmp/hh-load/household_users.dsc'
APPEND INTO TABLE household_users
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(household_id, user_id, role)
