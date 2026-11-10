<?php

namespace App\Console\Commands;

use App\Ops\Alerts;
use App\Ops\Backups;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * php artisan db:backup — a consistent mysqldump of the whole database,
 * gzipped onto the backup disk, with a manifest (size, sha256, rows per
 * table). Keeps the newest BACKUP_KEEP. Runs daily at 03:00 at home
 * (routes/console.php); docs/backups.md has the restore steps.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--connection= : default: the default connection}';

    protected $description = 'Back up the database (mysqldump, gzip) to the backup disk and prune old backups';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $name = Backups::newName();
        $started = microtime(true);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'hh-backup-');

        try {
            $tables = Backups::rowCounts($connection);

            // --single-transaction: a consistent snapshot without locking InnoDB tables;
            // --no-tablespaces: doesn't need the PROCESS privilege
            $dump = Backups::client(config('homehelper.backups.mysqldump'), $connection, [
                '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--hex-blob',
                (string) config("database.connections.{$connection}.database"),
            ]);

            $gz = gzopen($tmp, 'wb6') ?: throw new RuntimeException("Cannot write {$tmp}.");
            $errors = '';
            $dump->run(function (string $type, string $buffer) use ($gz, &$errors) {
                $type === Process::OUT ? gzwrite($gz, $buffer) : $errors .= $buffer;
            });
            gzclose($gz);

            if (! $dump->isSuccessful()) {
                throw new RuntimeException('mysqldump failed: '.trim($errors ?: $dump->getErrorOutput()));
            }

            $manifest = [
                'name' => $name,
                'file' => "{$name}.sql.gz",
                'created_at' => CarbonImmutable::now()->toIso8601String(),
                'database' => config("database.connections.{$connection}.database"),
                'bytes' => filesize($tmp),
                'sha256' => hash_file('sha256', $tmp),
                'seconds' => round(microtime(true) - $started, 2),
                'tables' => $tables,
                'rows' => array_sum($tables),
            ];

            $disk = Backups::disk();
            $disk->putFileAs(Backups::path(), new File($tmp), "{$name}.sql.gz");
            $disk->put(Backups::path("{$name}.json"), (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $pruned = $this->prune();
        } catch (Throwable $e) {
            Alerts::raise('backup-failed', 'Database backup failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($tmp);
        }

        $this->info(sprintf(
            'Backed up %s: %d tables, %s rows, %s KB in %.1f s → %s%s',
            $manifest['database'], count($tables), number_format($manifest['rows']),
            number_format($manifest['bytes'] / 1024, 1), $manifest['seconds'],
            Backups::path($manifest['file']),
            $pruned ? " ({$pruned} old backup(s) removed)" : '',
        ));

        return self::SUCCESS;
    }

    /** Keeps the newest `keep` backups; returns how many it removed */
    private function prune(): int
    {
        $old = array_slice(Backups::all(), max(1, (int) config('homehelper.backups.keep')));
        foreach ($old as $backup) {
            Backups::disk()->delete([Backups::path($backup['file']), Backups::path($backup['name'].'.json')]);
        }

        return count($old);
    }
}
