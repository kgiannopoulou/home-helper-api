-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/chores.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/chores.csv'
BADFILE '/tmp/hh-load/chores.bad'
DISCARDFILE '/tmp/hh-load/chores.dsc'
APPEND INTO TABLE chores
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, room_id, name, every_days, minutes)
