<?php

namespace App\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Database backups on a filesystem disk (config homehelper.backups).
 *
 * Each backup is two files:
 *   backups/home-helper-20261110-030000.sql.gz    mysqldump output, gzipped
 *   backups/home-helper-20261110-030000.json      when, size, sha256, row count per table
 * The row counts are what the restore test compares against.
 */
class Backups
{
    public const PREFIX = 'home-helper-';

    public const RESTORE_RESULT = 'restore-test.json';

    public static function disk(): Filesystem
    {
        return Storage::disk(config('homehelper.backups.disk'));
    }

    public static function path(string $file = ''): string
    {
        return trim(config('homehelper.backups.path'), '/').($file === '' ? '' : '/'.$file);
    }

    /**
     * Backups on the disk, newest first, as their manifests.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $disk = self::disk();
        $files = array_filter($disk->files(self::path()), fn (string $f) => str_starts_with(basename($f), self::PREFIX) && str_ends_with($f, '.json'));
        rsort($files); // the names sort by time

        $manifests = [];
        foreach ($files as $file) {
            /** @var array<string, mixed>|null $manifest */
            $manifest = json_decode((string) $disk->get($file), true);
            if (is_array($manifest)) {
                $manifests[] = $manifest;
            }
        }

        return $manifests;
    }

    /** @return array<string, mixed>|null */
    public static function latest(): ?array
    {
        return self::all()[0] ?? null;
    }

    /** @return array<string, mixed>|null the last restore test's result */
    public static function lastRestoreTest(): ?array
    {
        $file = self::path(self::RESTORE_RESULT);

        return self::disk()->exists($file) ? json_decode((string) self::disk()->get($file), true) : null;
    }

    public static function newName(): string
    {
        return self::PREFIX.CarbonImmutable::now('UTC')->format('Ymd-His');
    }

    /**
     * mysqldump/mysql with the connection's settings. The password goes in
     * MYSQL_PWD, not on the command line where `ps` would show it.
     *
     * @param  list<string>  $args
     */
    public static function client(string $binary, string $connection, array $args): Process
    {
        $c = config("database.connections.{$connection}");
        if (($c['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException("Backups need a MySQL connection; {$connection} is ".($c['driver'] ?? 'unknown').'.');
        }

        $process = new Process(
            [$binary, '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], '--default-character-set=utf8mb4', ...$args],
            env: ['MYSQL_PWD' => (string) $c['password']],
            timeout: 3600,
        );

        return $process;
    }

    /**
     * Rows per table, read on a connection of its own: never inside a
     * transaction the caller has open, so it sees what mysqldump sees.
     *
     * @return array<string, int>
     */
    public static function rowCounts(string $connection, ?string $database = null): array
    {
        $name = self::separateConnection($connection, $database);

        try {
            $db = DB::connection($name);
            // (MySQL 8 returns information_schema columns upper-case: alias them)
            $tables = collect($db->select(
                "select table_name as name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name",
                [$db->getDatabaseName()],
            ))->pluck('name');

            $counts = [];
            foreach ($tables as $table) {
                $counts[(string) $table] = $db->table((string) $table)->count();
            }

            return $counts;
        } finally {
            DB::purge($name);
        }
    }

    /**
     * A second connection with the same settings (optionally to another
     * database). DDL such as DROP DATABASE commits any open transaction, so
     * it never runs on the app's own connection.
     */
    public static function separateConnection(string $connection, ?string $database = null): string
    {
        $name = 'backups_'.$connection.'_'.($database ?? 'same');
        config(["database.connections.{$name}" => array_merge(
            config("database.connections.{$connection}"),
            $database ? ['database' => $database] : [],
        )]);
        DB::purge($name);

        return $name;
    }
}
