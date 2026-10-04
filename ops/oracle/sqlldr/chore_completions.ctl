-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/chore_completions.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/chore_completions.csv'
BADFILE '/tmp/hh-load/chore_completions.bad'
DISCARDFILE '/tmp/hh-load/chore_completions.dsc'
APPEND INTO TABLE chore_completions
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, chore_id, user_id, done_at TIMESTAMP "YYYY-MM-DD HH24:MI:SS", minutes)
