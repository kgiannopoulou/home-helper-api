<?php

namespace App\Console\Commands;

use App\Ops\Alerts;
use App\Ops\Backups;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * php artisan db:restore-test — proves a backup can be restored, without
 * touching the live database: checks the sha256, restores it into a scratch
 * database (BACKUP_RESTORE_DATABASE, created and dropped), and compares the
 * row count of every table with the manifest. Weekly, after the Sunday backup.
 *
 * To really restore (docs/backups.md): db:restore-test --keep restores into
 * the scratch database and leaves it there to look at or switch to.
 */
class RestoreTest extends Command
{
    protected $signature = 'db:restore-test
        {backup? : name, e.g. home-helper-20261110-030000; default: the newest}
        {--connection= : default: the default connection}
        {--keep : leave the restored database in place}';

    protected $description = 'Restore a backup into a scratch database and check every table\'s row count';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $target = (string) config('homehelper.backups.restore_database');
        $live = (string) config("database.connections.{$connection}.database");
        $started = microtime(true);
        $sql = (string) tempnam(sys_get_temp_dir(), 'hh-restore-');
        $admin = Backups::separateConnection($connection);
        // Only a database this run created is ever dropped
        $created = false;
        /** @var array<string, mixed> $result */
        $result = ['at' => CarbonImmutable::now()->toIso8601String(), 'backup' => null, 'status' => 'failed'];

        try {
            if ($target === $live || ! preg_match('/^\w+$/', $target)) {
                throw new RuntimeException("Refusing to restore into \"{$target}\": it must be a separate scratch database.");
            }

            $manifest = $this->manifest();
            $result['backup'] = $manifest['name'];

            // 1. The file is the one that was written
            $gz = Backups::disk()->get(Backups::path($manifest['file']));
            if ($gz === null || hash('sha256', $gz) !== $manifest['sha256']) {
                throw new RuntimeException("{$manifest['file']} is missing or its sha256 doesn't match the manifest.");
            }
            file_put_contents($sql, gzdecode($gz) ?: throw new RuntimeException("{$manifest['file']} is not valid gzip."));
            unset($gz);

            // 2. Restore it into the scratch database
            DB::connection($admin)->statement("drop database if exists `{$target}`");
            DB::connection($admin)->statement("create database `{$target}` character set utf8mb4 collate utf8mb4_unicode_ci");
            $created = true;

            $restore = Backups::client(config('homehelper.backups.mysql'), $connection, [$target]);
            $restore->setInput(fopen($sql, 'r') ?: throw new RuntimeException("Cannot read {$sql}."));
            $restore->run();
            if (! $restore->isSuccessful()) {
                throw new RuntimeException('mysql failed: '.trim($restore->getErrorOutput()));
            }

            // 3. Every table is there, with the same number of rows
            $restored = Backups::rowCounts($connection, $target);
            $mismatches = [];
            foreach ($manifest['tables'] as $table => $rows) {
                if (($restored[$table] ?? null) !== $rows) {
                    $mismatches[$table] = ['backup' => $rows, 'restored' => $restored[$table] ?? 'missing'];
                }
            }

            $result = [
                ...$result,
                'status' => $mismatches ? 'failed' : 'passed',
                'tables' => count($restored),
                'rows' => array_sum($restored),
                'seconds' => round(microtime(true) - $started, 2),
                'mismatches' => $mismatches,
            ];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        } finally {
            @unlink($sql);
            if ($created && ! $this->option('keep')) {
                try {
                    DB::connection($admin)->statement("drop database if exists `{$target}`");
                } catch (Throwable) {
                    // reported through the result already, if the restore got that far
                }
            }
        }

        Backups::disk()->put(Backups::path(Backups::RESTORE_RESULT), (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($result['status'] !== 'passed') {
            Alerts::raise('restore-test-failed', 'Backup restore test failed', [
                'backup' => $result['backup'],
                'error' => $result['error'] ?? null,
                'mismatches' => $result['mismatches'] ?? [],
            ]);
            $this->error('Restore test failed: '.($result['error'] ?? json_encode($result['mismatches'])));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Restored %s into %s: %d tables, %s rows, all counts match (%.1f s)%s',
            $result['backup'], $target, $result['tables'], number_format($result['rows']), $result['seconds'],
            $this->option('keep') ? '. Kept it.' : '. Dropped it again.',
        ));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $name = $this->argument('backup');
        if (! $name) {
            return Backups::latest() ?? throw new RuntimeException('There are no backups yet: run php artisan db:backup.');
        }

        $file = Backups::path("{$name}.json");
        if (! Backups::disk()->exists($file)) {
            throw new RuntimeException("No backup called {$name}.");
        }

        return json_decode((string) Backups::disk()->get($file), true);
    }
}
