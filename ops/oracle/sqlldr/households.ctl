-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/households.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/households.csv'
BADFILE '/tmp/hh-load/households.bad'
DISCARDFILE '/tmp/hh-load/households.dsc'
APPEND INTO TABLE households
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, name, currency, created_at DATE "YYYY-MM-DD")
