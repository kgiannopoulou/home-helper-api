-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/todos.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/todos.csv'
BADFILE '/tmp/hh-load/todos.bad'
DISCARDFILE '/tmp/hh-load/todos.dsc'
APPEND INTO TABLE todos
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, user_id, title, due_on DATE "YYYY-MM-DD", minutes, done_at TIMESTAMP "YYYY-MM-DD HH24:MI:SS")
