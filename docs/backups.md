# Backups

Day 6 of [Phase 1](phase-1.md): automated database backups, and a tested restore. A backup that has never been restored is a hope, not a backup, so the restore is tested every week, automatically.

## What runs

| When (home time) | Command | What it does |
|---|---|---|
| Every night 03:00 | `php artisan db:backup` | `mysqldump --single-transaction` of the whole database, gzipped onto the backup disk, with a manifest; keeps the newest 14 |
| Sundays 03:30 | `php artisan db:restore-test` | Restores the newest backup into a scratch database, compares every table's row count with the manifest, drops the scratch database |

Both raise an ops alert when they fail ([operations.md](operations.md)), and `/system/health` turns red when the newest backup is older than 26 hours or the last restore test didn't pass.

Each backup is two files on `BACKUP_DISK` under `backups/`:

```
home-helper-20261110-092200.sql.gz    the dump
home-helper-20261110-092200.json      created_at, bytes, sha256, rows per table
```

The row counts are read on a connection of their own just before the dump starts, so they see what the dump's snapshot sees.

### Why these choices

- **`--single-transaction`**: a consistent snapshot of every InnoDB table without locking them, so the phones keep syncing during the backup.
- **`--no-tablespaces`**: the backup user doesn't need the global `PROCESS` privilege.
- **Password in `MYSQL_PWD`**, not `--password=` on the command line, where `ps` would show it.
- **sha256 in the manifest**: the restore test refuses a file that changed or was cut short.
- **A scratch database, never the live one**: `db:restore-test` refuses if `BACKUP_RESTORE_DATABASE` is the app's database, and only ever drops a database it created itself in that run. (An early version dropped the target in its cleanup even after refusing, which the test suite caught: it dropped the test database.)
- **Off the server in production** (`BACKUP_DISK=s3`): a backup on the same disk is lost with the server. Needs `composer require league/flysystem-aws-s3-v3`; any S3-compatible store works.

## The restore drill, for real

On the local demo database (6 months of data), 2026-11-10:

```
$ php artisan db:backup
Backed up home_helper_api: 40 tables, 3,730 rows, 124.2 KB in 0.7 s → backups/home-helper-20261110-092200.sql.gz

$ php artisan db:restore-test
Restored home-helper-20261110-092200 into home_helper_restore_test: 40 tables, 3,730 rows, all counts match (3.1 s). Dropped it again.

$ cat storage/app/private/backups/restore-test.json
{
    "at": "2026-11-10T09:22:10+00:00",
    "backup": "home-helper-20261110-092200",
    "status": "passed",
    "tables": 40,
    "rows": 3730,
    "seconds": 3.07,
    "mismatches": []
}
```

40 tables: the 31 domain tables plus Laravel's own (`migrations`, `jobs`, `failed_jobs`, `cache`, `sessions`…). The view `v_weekly_spending` is restored too.

CI repeats this on every push (the "Backup and restore drill" step), and `tests/Feature/Ops/BackupTest.php` runs it with the real `mysqldump` and `mysql` clients: back up, restore with every count matching, a corrupted file failing the sha256 check with an alert, pruning to `BACKUP_KEEP`, and the refusal to touch the live database.

## Restoring for real

When the live database is lost or damaged:

```bash
php artisan down                                         # phones get 503 and retry later
php artisan db:restore-test home-helper-20261110-030000 --keep   # restores into BACKUP_RESTORE_DATABASE and keeps it
# look at it: mysql home_helper_restore_test -e "select count(*) from expenses"
```

Then either point `DB_DATABASE` at the restored database and `php artisan optimize`, or copy it over the live one:

```bash
gunzip -c home-helper-20261110-030000.sql.gz | mysql home_helper    # the dump has DROP TABLE IF EXISTS for each table
php artisan up
```

Phones that synced after the backup still have their newer rows, and send them on their next sync (last write wins), so a household loses less than the time since the backup.

## Locally

`mysqldump` and `mysql` must be on the PATH (or set `BACKUP_MYSQLDUMP` / `BACKUP_MYSQL`). On Windows the easiest is a container with PHP 8.4 and the MySQL 8.4 client on the Sail network:

```dockerfile
FROM ubuntu:25.10
RUN apt-get update && apt-get install -y --no-install-recommends php8.4-cli php8.4-mysql php8.4-xml \
    php8.4-mbstring php8.4-bcmath php8.4-curl php8.4-intl php8.4-zip mysql-client ca-certificates
```

```bash
docker build -t hh-php-ops .
docker run --rm --network home-helper-api_sail -v "$PWD:/app" -w /app \
  -e DB_HOST=mysql -e DB_USERNAME=root hh-php-ops php artisan db:backup
```

The restore test needs `CREATE` and `DROP` on the scratch database; the local Sail user only has rights on its own databases, hence `root` above. On a server, grant the app's user rights on `home_helper_restore_test` as well.
