-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/rooms.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/rooms.csv'
BADFILE '/tmp/hh-load/rooms.bad'
DISCARDFILE '/tmp/hh-load/rooms.dsc'
APPEND INTO TABLE rooms
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, name)
