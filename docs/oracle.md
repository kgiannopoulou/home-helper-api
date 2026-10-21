# Oracle lab

The same data in **Oracle Database Free** (26ai) in Docker, next to MySQL, to practise what an Oracle DBA does. Everything is a script in [`ops/oracle`](../ops/oracle), and **[the runbook](../ops/oracle/RUNBOOK.md)** has every command with its real output.

- **Setup:** the MySQL schema ported to Oracle SQL (`VARCHAR2`, `NUMBER(10,2)`, `DATE`, CHECK constraints instead of ENUM) in the pluggable database FREEPDB1. `php artisan oracle:generate` writes 3¾ years of data for 40 households (276,000 rows), loaded with SQL*Loader.
- **Partitioning:** `expenses`, `food_entries` and `chore_completions` get monthly **interval partitions**, which Oracle creates by itself. One month's query reads one partition (`PARTITION RANGE SINGLE`). Dropping a partition makes the GLOBAL index UNUSABLE unless you add `UPDATE GLOBAL INDEXES`, but leaves the LOCAL one alone.
- **Archiving:** ARCHIVELOG mode with a Fast Recovery Area. Months older than two years leave `expenses` by **`EXCHANGE PARTITION`**, which swaps a dictionary entry and copies no rows (0.24 s for a month), and a monthly `DBMS_SCHEDULER` job does the same.
- **Backup:** RMAN level 0 weekly and level 1 daily, with control file autobackup and a 7-day recovery window, checked with `RESTORE DATABASE VALIDATE`. Data Pump for the schema.
- **Recovery drills, timed:** a dropped table back from the recycle bin (3 s; the foreign key isn't restored), a bad `UPDATE` undone by point-in-time recovery of the PDB (23 s), a deleted datafile restored and recovered while the rest stays open (18 s), and a dropped user brought back with `impdp` (40 s).

| | Oracle | MySQL |
|---|---|---|
| **Logs for point-in-time recovery** | Archived redo logs (ARCHIVELOG mode, off by default): physical block changes, archived to the FRA | Binary log (on by default since 8.0): logical row events, replayed with `mysqlbinlog --stop-datetime` |
| **Physical backup** | RMAN, built in: incremental levels, block validation, retention policy, restore of one datafile or one PDB | Percona XtraBackup / MySQL Enterprise Backup: hot copy of the InnoDB files plus redo, `--prepare` before restoring |
| **Logical backup** | Data Pump (`expdp`/`impdp`): server-side, parallel, carries users and grants, remaps schemas | `mysqldump` / MySQL Shell dump utilities: SQL statements replayed on restore |
| **Partitions** | Interval partitions are added automatically. LOCAL and GLOBAL indexes, foreign keys allowed | `PARTITION BY RANGE` with every partition declared ahead. Every unique key must include the partition column, and partitioned tables can't have foreign keys |
| **Undo a mistake in place** | Flashback Drop, Flashback Query, Flashback Database | None built in: restore, then replay the binlog up to the mistake |

