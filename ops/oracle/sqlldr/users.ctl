-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/users.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/users.csv'
BADFILE '/tmp/hh-load/users.bad'
DISCARDFILE '/tmp/hh-load/users.dsc'
APPEND INTO TABLE users
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, name, email, created_at DATE "YYYY-MM-DD")
