-- sqlldr hh@FREEPDB1 control=/opt/hh/sqlldr/food_entries.ctl (scripts/load.sh runs them all)
OPTIONS (SKIP=1, ROWS=5000, BINDSIZE=4000000, READSIZE=4000000)
LOAD DATA
INFILE '/opt/hh/data/food_entries.csv'
BADFILE '/tmp/hh-load/food_entries.bad'
DISCARDFILE '/tmp/hh-load/food_entries.dsc'
APPEND INTO TABLE food_entries
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
TRAILING NULLCOLS
(id, household_id, user_id, entry_date DATE "YYYY-MM-DD", eaten_at TIMESTAMP "YYYY-MM-DD HH24:MI:SS", name, meal, kcal, protein, fiber)
