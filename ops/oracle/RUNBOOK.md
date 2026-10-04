# Oracle lab runbook

Home Helper's data in **Oracle AI Database 26ai Free** (the `gvenzl/oracle-free:23` image, release 23.26.3), next to the app's MySQL. The lab covers partitioning, redo log archiving and data archiving, RMAN backups, Data Pump, and four recovery drills. Each one was done on purpose and timed.

Every command below is a script in this folder. The outputs are real, from one run from an empty database (`scripts/run-lab.sh`), and saved in full in [`output/`](output). The quotes below are shortened.

| | |
|---|---|
| **Database** | CDB `FREE` with one pluggable database, `FREEPDB1`. The schema `HH` lives in its own tablespace `HH_DATA` |
| **Data** | 40 households, January 2023 to October 2026: 41,668 expenses, 148,110 meals, 81,142 chores done, 5,254 to-dos (`php artisan oracle:generate`, 31 MB of CSV) |
| **Free edition limits** | 2 CPU threads, 2 GB of memory for the database, 12 GB of user data. The lab uses about 1.3 GB of user data (`hh_data01.dbf`) |
| **Times** | Docker Desktop on Windows 11, WSL 2 backend |

Contents: [Setup](#setup) · [Partitioning](#partitioning) · [Archiving](#archiving) · [Backup](#backup) · [Recovery drills](#recovery-drills) · [Schedule](#schedule) · [Oracle and MySQL](#oracle-and-mysql)

---

## Setup

```bash
cd ops/oracle
cp .env.example .env                    # ORACLE_PASSWORD (SYS, SYSTEM) and HH_PASSWORD; .env is git-ignored
docker compose up -d
docker logs -f oracle                   # wait for: DATABASE IS READY TO USE!
docker exec -it oracle sqlplus / as sysdba
```

`docker-compose.yml` uses a named volume, `oracle-data`, for `/opt/oracle/oradata` (data files, redo logs, the Fast Recovery Area). It mounts this folder read-only at `/opt/hh`, so the container sees the scripts and the CSV files.

**Port the schema.** [`sql/02_schema.sql`](sql/02_schema.sql) is the MySQL schema in Oracle SQL:

| MySQL | Oracle |
|---|---|
| `char(26)` ULID | `VARCHAR2(26)` |
| `bigint` | `NUMBER(19)` |
| `decimal(10,2)` | `NUMBER(10,2)` |
| `varchar(n)` | `VARCHAR2(n)` |
| `date` | `DATE` (Oracle's `DATE` holds a time too; here it's always midnight) |
| `timestamp(3)` | `TIMESTAMP(3)` |
| `enum(...)` | `VARCHAR2` + `CHECK (... IN (...))`. Oracle has no ENUM type |
| a column named `date` | `entry_date`, because `DATE` is a reserved word |

**Generate and load the data.** `php artisan oracle:generate` writes 3¾ years of data as CSV, living the way the demo household does: a weekly shop, bills on their day, meals, chores around their schedule. [`scripts/setup.sh`](scripts/setup.sh) creates the tablespace and user ([`sql/01_user.sql`](sql/01_user.sql)) and the schema, then loads every file with SQL*Loader ([`sqlldr/*.ctl`](sqlldr)):

```bash
php artisan oracle:generate --to=2026-10-04          # on the host: ops/oracle/data/*.csv
docker exec oracle bash /opt/hh/scripts/setup.sh
```

```
households               40 loaded, 0 rejected,   3122 ms
expenses              41668 loaded, 0 rejected,   1449 ms
food_entries         148110 loaded, 0 rejected,  12576 ms
chore_completions     81142 loaded, 0 rejected,   4271 ms
todos                  5254 loaded, 0 rejected,    122 ms
```

It loads by conventional path, not direct path: a direct path load switches off foreign keys and CHECK constraints while it runs, and they stay off unless you ask SQL*Loader to `REENABLE` them. At this size the speed doesn't matter. Every row is checked as it goes in.

> **Gotcha.** `CREATE USER hh IDENTIFIED BY "&1"` with `SET ECHO ON` printed the password back (`old 1:` / `new 1:` lines) in the first run's log. `SET VERIFY OFF` in `01_user.sql` stops it. Passing passwords in a parameter file (Data Pump scripts) keeps them out of `ps` too.

**Optional, not done:** the `oci8` PHP extension and `yajra/laravel-oci8`, to run the Laravel API itself on Oracle.

---

## Partitioning

`expenses`, `food_entries` and `chore_completions` are partitioned by month with **interval partitioning**. The DDL names a single partition for everything before 2023, and Oracle adds a new partition the first time a row arrives for a new month:

```sql
CREATE TABLE expenses (
  id            VARCHAR2(26) NOT NULL,
  household_id  VARCHAR2(26) NOT NULL,
  entry_date    DATE NOT NULL,
  amount        NUMBER(10,2) NOT NULL CONSTRAINT expenses_amount_ck CHECK (amount > 0),
  category      VARCHAR2(20) NOT NULL,
  ...
)
PARTITION BY RANGE (entry_date)
INTERVAL (NUMTOYMINTERVAL(1, 'MONTH'))
(PARTITION p_start VALUES LESS THAN (DATE '2023-01-01'));

CREATE INDEX expenses_household_date_lix ON expenses (household_id, entry_date) LOCAL;
CREATE UNIQUE INDEX expenses_id_gix ON expenses (id) GLOBAL;
ALTER TABLE expenses ADD CONSTRAINT expenses_pk PRIMARY KEY (id) USING INDEX expenses_id_gix;
```

`chore_completions` uses a `TIMESTAMP(3)` key (`done_at`), and interval partitioning works on it the same way.

### The partitions Oracle made ([`sql/10_partitions.sql`](sql/10_partitions.sql), [output](output/10-partitions.txt))

```
TABLE_NAME             PARTITION INTERVAL                       PARTITION_COUNT
CHORE_COMPLETIONS      RANGE     NUMTOYMINTERVAL(1, 'MONTH')            1048575
EXPENSES               RANGE     NUMTOYMINTERVAL(1, 'MONTH')            1048575
FOOD_ENTRIES           RANGE     NUMTOYMINTERVAL(1, 'MONTH')            1048575

       POS PARTITION_NAME   HIGH_VALUE                                  NUM_ROWS
         1 P_START          TO_DATE(' 2023-01-01 00:00:00', ...                0
         2 SYS_P763         TO_DATE(' 2023-02-01 00:00:00', ...              930
         3 SYS_P764         TO_DATE(' 2023-03-01 00:00:00', ...              866
        ...
        46 SYS_P807         TO_DATE(' 2026-10-01 00:00:00', ...              885
        47 SYS_P808         TO_DATE(' 2026-11-01 00:00:00', ...              138
```

- **47 partitions per table:** `p_start`, then one per month from January 2023 to October 2026, named `SYS_Pnnn` by Oracle. Use `PARTITION FOR (DATE '2023-03-01')` to name one by a date in it, so you never need the generated name.
- `PARTITION_COUNT` for an interval table is always 1,048,575, the most it could ever have, not how many exist. `USER_TAB_PARTITIONS` has the real list.
- Every partition is its own segment, and a new partition segment starts at 8 MB. The 46 segments of `expenses` take **368 MB for 2 MB of rows**. Interval partitions by month suit tables with thousands of rows a month, not dozens. In a real system, `expenses` would partition by year or not at all.

### Partition pruning ([`sql/11_pruning.sql`](sql/11_pruning.sql), [output](output/11-pruning.txt))

```sql
EXPLAIN PLAN FOR
SELECT category, SUM(amount) FROM expenses
WHERE household_id = (SELECT MIN(id) FROM households)
  AND entry_date >= DATE '2026-09-01' AND entry_date < DATE '2026-10-01'
GROUP BY category;
SELECT * FROM TABLE(DBMS_XPLAN.DISPLAY(format => 'BASIC +PARTITION +PREDICATE'));
```

```
| Id  | Operation                                   | Name                        | Pstart| Pstop |
|   0 | SELECT STATEMENT                            |                             |       |       |
|   1 |  HASH GROUP BY                              |                             |       |       |
|   2 |   PARTITION RANGE SINGLE                    |                             |    46 |    46 |
|   3 |    TABLE ACCESS BY LOCAL INDEX ROWID BATCHED| EXPENSES                    |    46 |    46 |
|*  4 |     INDEX RANGE SCAN                        | EXPENSES_HOUSEHOLD_DATE_LIX |    46 |    46 |
```

| Query | Plan | Partitions read |
|---|---|---|
| One month (September 2026) | `PARTITION RANGE SINGLE` | 46 only |
| A quarter (July to September) | `PARTITION RANGE ITERATOR` | 44 to 46 |
| No date (`category = 'fun'`) | `PARTITION RANGE ALL` + `TABLE ACCESS FULL` | 1 to 1048575 (all) |
| `TO_CHAR(entry_date, 'YYYY-MM') = '2026-09'` | `PARTITION RANGE ALL` | all: a function on the key hides it from pruning |
| `food_entries`, one person's September | `PARTITION RANGE SINGLE` + local index | 46 only |
| `chore_completions`, 5 Sep to 5 Oct (`TIMESTAMP` key) | `PARTITION RANGE ITERATOR` | 46 to 47 |

The pruning happens at parse time, from the literal dates. The local index is searched only in the partitions that are left.

### Dropping a partition: LOCAL vs GLOBAL indexes ([`sql/12_drop_partition.sql`](sql/12_drop_partition.sql), [output](output/12-drop-partition.txt))

January 2023 went to the archive first (`INSERT ... SELECT`), then its partition was dropped **with no index clause**:

```
ALTER TABLE expenses DROP PARTITION FOR (DATE '2023-01-01');     Elapsed: 00:00:00.08

EXPENSES_ID_GIX (GLOBAL)              UNUSABLE
EXPENSES_HOUSEHOLD_DATE_LIX (LOCAL)   47 -> 46 index partitions

INSERT INTO expenses ...
ORA-01502: index 'HH.EXPENSES_ID_GIX' or partition of such index is in unusable state

ALTER INDEX expenses_id_gix REBUILD ONLINE;                     Elapsed: 00:00:00.47
```

February 2023 was dropped **with `UPDATE GLOBAL INDEXES`**:

```
ALTER TABLE expenses DROP PARTITION FOR (DATE '2023-02-01') UPDATE GLOBAL INDEXES;   Elapsed: 00:00:00.01

INDEX_NAME          STATUS    ORPHANED_ENTRIES
EXPENSES_ID_GIX     VALID     YES
```

- **LOCAL index:** its partition goes with the table partition. Nothing else to do; it never becomes unusable.
- **GLOBAL index:** it holds entries for every partition, so dropping one leaves entries pointing to rows that no longer exist. Without a clause, Oracle marks the whole index **UNUSABLE**. Lookups by `id` stop using it, and every insert fails (ORA-01502) until a rebuild, which reads every row of every partition.
- **`UPDATE GLOBAL INDEXES`** keeps it VALID. Since 12c it doesn't clean the entries straight away: the drop took 0.01 s and left them as **orphaned entries** (`ORPHANED_ENTRIES = YES`), which queries ignore. The `SYS.PMO_DEFERRED_GIDX_MAINT_JOB` job removes them later, or you can run `DBMS_PART.CLEANUP_GIDX`. **Always use it in production.**

---

## Archiving

### Archived redo logs ([`sql/20_archivelog.sql`](sql/20_archivelog.sql), [output](output/20-archivelog.txt))

Out of the box the database is in `NOARCHIVELOG` mode. Online redo logs are reused in a circle, so only a backup taken while the database is shut down can be restored, and only to that moment.

```
SQL> ARCHIVE LOG LIST
Database log mode              No Archive Mode
Automatic archival             Disabled
```

```sql
ALTER SYSTEM SET db_recovery_file_dest_size = 10G SCOPE = BOTH;
ALTER SYSTEM SET db_recovery_file_dest = '/opt/oracle/oradata/fra' SCOPE = BOTH;
SHUTDOWN IMMEDIATE
STARTUP MOUNT
ALTER DATABASE ARCHIVELOG;          -- the mode lives in the control file: only while MOUNTED
ALTER DATABASE OPEN;
ALTER PLUGGABLE DATABASE ALL OPEN;
ALTER PLUGGABLE DATABASE freepdb1 SAVE STATE;   -- reopen the PDB by itself after restarts
ALTER SYSTEM SWITCH LOGFILE;
```

```
Database log mode              Archive Mode
Automatic archival             Enabled
Archive destination            USE_DB_RECOVERY_FILE_DEST

 SEQUENCE# NAME                                                                  MB
        18 /opt/oracle/oradata/fra/FREE/archivelog/2026_10_04/o1_mf_1_18_od479cx7_.arc   12.7
        19 /opt/oracle/oradata/fra/FREE/archivelog/2026_10_04/o1_mf_1_19_od479d5d_.arc      0
        20 /opt/oracle/oradata/fra/FREE/archivelog/2026_10_04/o1_mf_1_20_od479d6x_.arc      0
```

Each log switch closes the current online redo log, and the archiver copies it to the **Fast Recovery Area**. It took about 9 s, including the restart. With every change since the last backup kept, the database can be recovered to any moment, which drills 2 and 3 rely on. The FRA also holds RMAN backups and control file autobackups, and Oracle deletes from it what the retention policy no longer needs when space runs short.

### Data archiving: EXCHANGE PARTITION ([`sql/21_exchange.sql`](sql/21_exchange.sql), [output](output/21-exchange.txt))

`expenses_archive` has the same columns plus `archived_at`, is compressed, and isn't partitioned. `expenses_archive_stage` is an empty table made with `CREATE TABLE ... FOR EXCHANGE WITH TABLE expenses`, which guarantees the same columns in the same order with the same types.

```sql
ALTER TABLE expenses
  EXCHANGE PARTITION FOR (DATE '2023-03-01')
  WITH TABLE expenses_archive_stage
  UPDATE GLOBAL INDEXES;
```

```
WHAT             DATA_OBJECT_ID          before
march partition           73333
stage table               73328

Table altered.                            Elapsed: 00:00:00.24

MARCH_ROWS  0       STAGE_ROWS  922

WHAT             DATA_OBJECT_ID          after
stage table               73333           <- March's old segment
```

Then the rows go into the archive, the stage is emptied, and the now-empty partition is dropped (0.02 s):

```
922 rows created.          (INSERT /*+ APPEND */ INTO expenses_archive SELECT ... FROM expenses_archive_stage)
Table truncated.
Table altered.             (DROP PARTITION FOR (DATE '2023-03-01') UPDATE GLOBAL INDEXES)
OLDEST_IN_EXPENSES  2023-04-01
```

**Why the exchange is almost instant:** no rows are copied. A partition and a table are both just segments: extents on disk, found through the data dictionary. `EXCHANGE PARTITION` swaps two dictionary entries. The partition now points at the stage table's (empty) segment, and the stage table at the partition's. Above, `DATA_OBJECT_ID` 73333, which held March's rows, belongs to the stage table afterwards. The cost is the same for 900 rows or 90 million. Here the 0.24 s is mostly `UPDATE GLOBAL INDEXES` removing March's entries from the primary key index. A copy with `INSERT ... SELECT` plus `DELETE` would write every row twice, with undo and redo for each.

### The monthly job ([`sql/22_archive_job.sql`](sql/22_archive_job.sql), [output](output/22-archive-job.txt))

The procedure `archive_expenses(p_keep_months => 24)` repeats the same steps for every month older than 24 months. A `DBMS_SCHEDULER` job runs it on the 1st of every month at 03:00, inside the database:

```
JOB_NAME                   REPEAT_INTERVAL                                  NEXT_RUN_DATE                        ENABL
ARCHIVE_EXPENSES_MONTHLY   FREQ=MONTHLY; BYMONTHDAY=1; BYHOUR=3; BYMINUTE=0 01-NOV-26 03.00.29.545671 AM +00:00  TRUE

2023-04: 911 rows archived in .102484 s
2023-05: 943 rows archived in .086682 s
...
2024-09: 905 rows archived in .081628 s
Elapsed: 00:00:01.59

OLDEST_IN_  ROWS_LEFT          ROWS_ARCHIVED
2024-10-01      22227                  19441
```

The first run caught up on 18 months, at 0.08 to 0.1 s each. [`scripts/archive-month.sh`](scripts/archive-month.sh) runs the same procedure from cron instead of the scheduler.

---

## Backup

### RMAN settings ([`rman/configure.rman`](rman/configure.rman))

```
CONFIGURE CONTROLFILE AUTOBACKUP ON;
CONFIGURE RETENTION POLICY TO RECOVERY WINDOW OF 7 DAYS;
CONFIGURE DEVICE TYPE DISK BACKUP TYPE TO COMPRESSED BACKUPSET;
CONFIGURE ARCHIVELOG DELETION POLICY TO BACKED UP 1 TIMES TO DISK;
```

- **Control file autobackup:** after every backup, RMAN also saves the control file and spfile, which list every backup. Without them you can't restore at all.
- **A recovery window of 7 days:** keep whatever is needed to restore to any moment of the last week. Older backups and logs become `OBSOLETE`.
- **Compressed:** a level 0 of the 4.3 GB of datafiles (CDB root, seed and FREEPDB1) takes about 470 MB in the FRA.

### Level 0 and level 1 ([`scripts/rman-level0.sh`](scripts/rman-level0.sh), [`scripts/rman-level1.sh`](scripts/rman-level1.sh))

```
BACKUP INCREMENTAL LEVEL 0 DATABASE TAG 'WEEKLY_L0' PLUS ARCHIVELOG TAG 'WEEKLY_ARCH';   -- 54 s
-- 783 meals and 40 expenses added (sql/30_add_data.sql)
BACKUP INCREMENTAL LEVEL 1 DATABASE TAG 'DAILY_L1' PLUS ARCHIVELOG TAG 'DAILY_ARCH';     -- 11 s
```

Level 0 copies every used block: about 470 MB, compressed. Level 1 copies only the blocks changed since the last level 0 or 1: **1 MB** after a day's worth of new rows, in 11 s against 54 s. `PLUS ARCHIVELOG` backs up the archived logs before and after, so the backup can be recovered to a consistent point on its own.

### Checking them ([`rman/check.rman`](rman/check.rman), [output](output/34-rman-check.txt))

```
RMAN> LIST BACKUP SUMMARY;
Key     TY LV S Device Type Completion Time     #Pieces #Copies Compressed Tag
1       B  A  A DISK        2026-10-04 09:32:39 1       1       YES        WEEKLY_ARCH
2       B  0  A DISK        2026-10-04 09:32:49 1       1       YES        WEEKLY_L0      <- FREEPDB1
3       B  0  A DISK        2026-10-04 09:33:12 1       1       YES        WEEKLY_L0      <- CDB root
4       B  0  A DISK        2026-10-04 09:33:26 1       1       YES        WEEKLY_L0      <- PDB$SEED
5       B  A  A DISK        2026-10-04 09:33:27 1       1       YES        WEEKLY_ARCH
6       B  F  A DISK        2026-10-04 09:33:28 1       1       YES        TAG20261004T093328   <- control file autobackup
8       B  1  A DISK        2026-10-04 09:33:35 1       1       YES        DAILY_L1
9       B  1  A DISK        2026-10-04 09:33:37 1       1       YES        DAILY_L1
...
RMAN> REPORT NEED BACKUP;
Report of files that must be backed up to satisfy 7 days recovery window
(none)
RMAN> RESTORE DATABASE VALIDATE;
channel ORA_DISK_1: validation complete, elapsed time: 00:00:15
channel ORA_DISK_1: validation complete, elapsed time: 00:00:25
channel ORA_DISK_1: validation complete, elapsed time: 00:00:15
Finished restore at 2026-10-04 09:34:38
```

`RESTORE DATABASE VALIDATE` reads the backup pieces a restore would use and checks every block, without writing anything. It only reads the **level 0** pieces, because the level 1 is applied during recovery, not restore. `VALIDATE BACKUPSET 8, 9` checks the level 1 pieces too. `REPORT NEED BACKUP` lists nothing: every datafile is covered for the 7-day window.

### A logical backup with Data Pump ([`scripts/datapump-export.sh`](scripts/datapump-export.sh), [output](output/35-datapump-export.txt))

```bash
docker exec oracle bash /opt/hh/scripts/datapump-export.sh
# expdp system@FREEPDB1 schemas=HH directory=DATA_PUMP_DIR dumpfile=hh.dmp logfile=hh-export.log
```

```
. . exported "HH"."EXPENSES":"SYS_P806"                   97.2 KB     929 rows
. . exported "HH"."EXPENSES_ARCHIVE"                         2 MB   19441 rows
. . exported "HH"."TODOS"                                461.7 KB    5254 rows
Dump file set for SYSTEM.SYS_EXPORT_SCHEMA_01 is:
  /opt/oracle/admin/FREE/dpdump/5A4CDD399CF90FFBE0632600010A057A/hh.dmp
Job "SYSTEM"."SYS_EXPORT_SCHEMA_01" successfully completed ... elapsed 0 00:00:36
```

- **Exported as SYSTEM, not as HH.** A user exporting its own schema gets its tables and code but not `CREATE USER` with its grants and quota. That needs the `DATAPUMP_EXP_FULL_DATABASE` role. With SYSTEM's export, drill 4 could bring the user back after `DROP USER`.
- **Where the dump went:** `DATA_PUMP_DIR` in the PDB is under `/opt/oracle/admin`, not in the data volume. A recreated container loses it, so copy it out (`docker cp`) or point a directory object at the volume.

**RMAN or Data Pump?** RMAN copies **blocks**. It's the backup of the whole database: point-in-time recovery, one lost file, everything up to the last committed change. Data Pump copies **rows and DDL** as of one moment. Use it to move one schema to another database (another version, platform or character set), refresh a test copy from production, keep a copy of a schema before a risky release, or bring back one dropped user (drill 4). It's no replacement for RMAN: it has nothing after the moment it was taken.

---

## Recovery drills

Each drill was done on purpose, timed, and checked afterwards against the counts and totals from before.

| Drill | What broke | How it was fixed | Time | Checked afterwards |
|---|---|---|---:|---|
| 1 | `DROP TABLE todos` | `FLASHBACK TABLE todos TO BEFORE DROP` | 3 s | 5,254 to-dos, 4,241 done, as before. The indexes and the foreign key needed attention (below) |
| 2 | `UPDATE expenses SET amount = 0.01`, committed | RMAN point-in-time recovery of FREEPDB1 | 23 s | 22,267 expenses, total 1,544,323.94, as before the update |
| 3 | `hh_data01.dbf` deleted | RMAN `RESTORE` and `RECOVER DATAFILE 25` | 18 s | same total; the rest of the database stayed open throughout |
| 4 | `DROP USER hh CASCADE` | `impdp` from the Data Pump file | 40 s | every table's count, 16 indexes, 120 partitions, the job, 0 invalid objects |

### 1. A dropped table ([`drills/1-dropped-table.sh`](drills/1-dropped-table.sh), [output](output/41-drill-dropped-table.txt))

```
DROP TABLE todos;
SELECT COUNT(*) FROM todos;   ORA-00942: table or view "HH"."TODOS" does not exist

ORIGINAL_NAME            OBJECT_NAME                      TYPE
TODOS                    BIN$XQF8DKjuBD7gYwIAFqyY+A==$0   TABLE
TODOS_HOUSEHOLD_DUE_IX   BIN$XQF8DKjsBD7gYwIAFqyY+A==$0   INDEX
TODOS_PK                 BIN$XQF8DKjtBD7gYwIAFqyY+A==$0   INDEX

FLASHBACK TABLE todos TO BEFORE DROP;     Flashback complete.   Elapsed: 00:00:00.05
     TODOS       DONE
      5254       4241
```

A dropped table isn't deleted. It's renamed into the **recycle bin** with its segments, until the space is needed or someone purges it. What came back, and what didn't:

- **The rows:** all of them.
- **Indexes and the primary key:** back, but still under their `BIN$` names. The drill renames them (`TODOS_PK`, `TODOS_HOUSEHOLD_DUE_IX`).
- **The foreign key `TODOS_HOUSEHOLD_FK`:** gone. Flashback Drop doesn't restore referential constraints, so the drill adds it again. Without that, to-dos could point to households that don't exist.
- Doesn't work after `DROP TABLE ... PURGE`, or for tables in the SYSTEM tablespace.

### 2. Bad data: point-in-time recovery of the PDB ([`drills/2-bad-data-pitr.sh`](drills/2-bad-data-pitr.sh), [output](output/42-drill-bad-data-pitr.txt))

```
  EXPENSES      TOTAL
     22267 1544323.94
last good moment: 2026-10-04 09:35:24

UPDATE expenses SET amount = 0;
ORA-02290: check constraint (HH.EXPENSES_AMOUNT_CK) violated      <- the schema refuses the lab's mistake
UPDATE expenses SET amount = 0.01;
22267 rows updated.
Commit complete.
     22267     222.67
```

```
ALTER PLUGGABLE DATABASE freepdb1 CLOSE IMMEDIATE;
RUN {
  SET UNTIL TIME "TO_DATE('2026-10-04 09:35:24', 'YYYY-MM-DD HH24:MI:SS')";
  RESTORE PLUGGABLE DATABASE freepdb1;     -- level 0: restore complete, elapsed time: 00:00:15
  RECOVER PLUGGABLE DATABASE freepdb1;     -- level 1, then archived logs 24..26 up to 09:35:24
}
ALTER PLUGGABLE DATABASE freepdb1 OPEN RESETLOGS;
recovery took 23 s

  EXPENSES      TOTAL
     22267 1544323.94
```

- Only FREEPDB1 went back in time. The CDB root and any other PDB stayed open and current. That's possible because the PDB has its own undo (local undo, the default since 12.2), so there's no auxiliary instance to build.
- Recovery is **restore, then roll forward**: the level 0 datafiles, then the level 1 changed blocks, then the redo in the archived logs up to the chosen second.
- `OPEN RESETLOGS` starts a new **PDB incarnation** (`V$PDB_INCARNATION` gains a row). Backups from before still work for the old incarnation. A new level 0 straight afterwards is good practice, and `run-lab.sh` takes one.
- Everything after 09:35:24 is lost too, not just the mistake. When you only need some rows back, **Flashback Query** (`SELECT ... AS OF TIMESTAMP`) is gentler, if the undo still has them.

### 3. A lost datafile ([`drills/3-lost-datafile.sh`](drills/3-lost-datafile.sh), [output](output/44-drill-lost-datafile.txt))

```
   FILE_ID FILE_NAME                                                       MB STATUS    ONLINE_
        25 /opt/oracle/oradata/FREE/FREEPDB1/hh_data01.dbf               1300 AVAILABLE ONLINE

ALTER DATABASE DATAFILE 25 OFFLINE;
removed '/opt/oracle/oradata/FREE/FREEPDB1/hh_data01.dbf'
SELECT COUNT(*) FROM expenses;
ORA-00376: file 25 cannot be read at this time

RMAN> RESTORE DATAFILE 25;     restoring datafile 00025 ... restore complete, elapsed time: 00:00:15
RMAN> RECOVER DATAFILE 25;     media recovery complete, elapsed time: 00:00:01
ALTER DATABASE DATAFILE 25 ONLINE;
drill took 18 s

  EXPENSES      TOTAL
     22267 1544323.94
```

Only Home Helper's tablespace was offline. Everything else in the PDB and the CDB kept working, which is why the schema has its own tablespace. RMAN restored the one file from the level 0 and rolled it forward with the archived and online redo, to the latest committed change. Nothing was lost.

### 4. A dropped schema ([`drills/4-schema-restore.sh`](drills/4-schema-restore.sh), [output](output/45-drill-schema-restore.txt))

```
DROP USER hh CASCADE;
Processing object type SCHEMA_EXPORT/USER
Processing object type SCHEMA_EXPORT/SYSTEM_GRANT
Processing object type SCHEMA_EXPORT/TABLESPACE_QUOTA
Processing object type SCHEMA_EXPORT/TABLE/TABLE_DATA
. . imported "HH"."EXPENSES_ARCHIVE"                         2 MB   19441 rows
. . imported "HH"."TODOS"                                461.7 KB    5254 rows
Processing object type SCHEMA_EXPORT/TABLE/CONSTRAINT/REF_CONSTRAINT
Processing object type SCHEMA_EXPORT/POST_SCHEMA/PROCOBJ/SCHEDULER
Job "SYSTEM"."SYS_IMPORT_SCHEMA_01" successfully completed ... elapsed 0 00:00:31
drill took 40 s

  EXPENSES   ARCHIVED       FOOD COMPLETIONS      TODOS
     22267      19441     148893       81142       5254
OBJECT_TYPE        COUNT(*)
INDEX                    16
INDEX PARTITION         120
JOB                       1
PROCEDURE                 1
TABLE                    12
TABLE PARTITION         120
INVALID_OBJECTS  0
```

The import created the user again, with its password, grants and quota, then the tables and their partitions, indexes, constraints, the procedure and the scheduler job. It brought back the schema as it was when exported, not as it was a second before the drop: anything written after the export would be lost. With RMAN, the alternative is a point-in-time recovery as in drill 2, which takes the whole PDB back.

---

## Schedule

| When | What | Command (host crontab) |
|---|---|---|
| Sunday 02:00 | RMAN level 0 + archived logs, delete obsolete | `0 2 * * 0 docker exec oracle bash /opt/hh/scripts/rman-level0.sh` |
| Monday to Saturday 02:00 | RMAN level 1 + archived logs, delete obsolete | `0 2 * * 1-6 docker exec oracle bash /opt/hh/scripts/rman-level1.sh` |
| Daily 03:30 | Data Pump export of HH | `30 3 * * * docker exec oracle bash /opt/hh/scripts/datapump-export.sh hh-$(date +\%a).dmp` (a week of dumps, one per weekday) |
| 1st of the month 03:00 | Archive expenses older than 24 months | `DBMS_SCHEDULER` job `ARCHIVE_EXPENSES_MONTHLY` (or `scripts/archive-month.sh` from cron) |

Restores are only as good as the last test. Run the drills again after changing the backup scripts.

---

## Oracle and MySQL

| | Oracle | MySQL 8 (the app's database) |
|---|---|---|
| **Change log for recovery** | **Archived redo logs:** the online redo logs, copied to the FRA when full (ARCHIVELOG mode, off by default). Physical: block changes, for every tablespace | **Binary log:** on by default since 8.0. Logical: row events (`binlog_format=ROW`). Replayed with `mysqlbinlog --stop-datetime=…`. Also feeds replication |
| **Point in time** | `RMAN SET UNTIL TIME/SCN` + `RESTORE/RECOVER`, for the whole database, one PDB, a tablespace or a datafile | Restore the last full backup, then replay the binlog to the moment. Whole server, or one database with `--database` filtering |
| **Physical backup** | **RMAN:** built in. Level 0/1 incrementals, block checks, compression, a catalog of every backup in the control file, retention policies, `RESTORE … VALIDATE` | **Percona XtraBackup** (or MySQL Enterprise Backup): copies InnoDB files while the server runs, plus the redo written meanwhile. Incrementals by LSN. `--prepare` before a restore |
| **Logical backup** | **Data Pump** (`expdp`/`impdp`): server-side, parallel, filters by schema, table or query, remaps schemas and tablespaces, carries users and grants | **`mysqldump`** / **`mysqlpump`** / MySQL Shell `util.dumpSchemas()`: SQL or TSV files, client-side. Restoring replays every `INSERT` |
| **Undo a mistake without a restore** | Flashback Drop (recycle bin), Flashback Query (`AS OF`), Flashback Table/Database | None built in: a restore plus binlog replay up to just before the mistake |
| **Partitioning** | **Interval partitions:** `INTERVAL (NUMTOYMINTERVAL(1,'MONTH'))` adds a partition when a new month's first row arrives. LOCAL and GLOBAL indexes. `EXCHANGE PARTITION` with a table | **`PARTITION BY RANGE`** (or `RANGE COLUMNS`): every partition named in advance, so a monthly job has to `REORGANIZE` a `MAXVALUE` partition or add the next month. Every index is local, and every unique key, the primary key included, must contain the partition column. No foreign keys on partitioned tables. `EXCHANGE PARTITION` exists too |
| **Pruning in the plan** | `PARTITION RANGE SINGLE/ITERATOR/ALL` with `Pstart`/`Pstop` | `EXPLAIN` shows the `partitions` column (`p202609`) |
| **Schemas** | A user *is* a schema. A CDB holds pluggable databases | A database is a schema. No users-as-schemas, no containers |

For Home Helper, that last partitioning row matters most. In MySQL, partitioning `expenses` by month would mean dropping the `expenses` → `households` foreign key, and making the primary key `(id, date)`. That's why the app's MySQL schema isn't partitioned and uses covering indexes instead (Phase 3).
