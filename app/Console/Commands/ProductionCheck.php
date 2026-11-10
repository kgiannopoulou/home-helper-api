<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks the configuration a real server runs with, before a deploy goes
 * live (docs/deployment.md). Exits 1 if anything would be unsafe: debug on,
 * demo logins in the database, the Sail password, mail to the log file…
 * Warnings are things to look at that may be fine on purpose.
 */
class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Check that the configuration is safe for production; exits 1 on a failure';

    /** Passwords that only belong in .env.example */
    private const WEAK_DB_PASSWORDS = ['', 'password', 'secret', 'root', 'sail'];

    /** @var list<array{string, string, string}> [status, check, detail] */
    private array $rows = [];

    public function handle(): int
    {
        $this->check('APP_ENV is production', app()->isProduction(), 'is '.app()->environment(), warnOnly: true);
        $this->check('APP_DEBUG is off', ! config('app.debug'), 'debug pages show code, queries and environment values');
        $this->check('APP_KEY is set', filled(config('app.key')), 'run php artisan key:generate once, and keep it');
        $this->check('APP_URL uses https', str_starts_with((string) config('app.url'), 'https://'), 'is '.config('app.url'));

        $db = config('database.connections.'.config('database.default'));
        $this->check('Database password is not a default', ! in_array((string) ($db['password'] ?? ''), self::WEAK_DB_PASSWORDS, true), 'set a long random DB_PASSWORD');
        $this->check('Database user is not root', ($db['username'] ?? '') !== 'root', 'use a user with rights on this database only');
        $this->checkDemoAccounts();

        $this->check('Mail is really sent', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER is '.config('mail.default').': invites would never arrive');
        $this->check('Queue is not sync', ! in_array(config('queue.default'), ['sync', 'null'], true), 'QUEUE_CONNECTION=redis, with a worker running');
        $this->check('Cache persists', config('cache.default') !== 'array', 'CACHE_STORE=redis');
        $this->check('Session cookie is HTTPS-only', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE=true');
        $this->check('Log level is not debug', config('logging.channels.json.level') !== 'debug', 'LOG_LEVEL=info: debug logs can hold personal data', warnOnly: true);
        $this->check('API tokens expire', filled(config('sanctum.expiration')), 'SANCTUM_TOKEN_EXPIRATION (minutes)');
        $this->check('CORS is limited to known origins', config('cors.allowed_origins') !== ['*'], 'CORS_ALLOWED_ORIGINS=https://your-domain', warnOnly: true);

        $this->check('Someone can open /system/health', filled(config('homehelper.ops.admins')), 'OPS_ADMIN_EMAILS', warnOnly: true);
        $this->check('Alerts are emailed', filled(config('homehelper.ops.alert_email')), 'OPS_ALERT_EMAIL', warnOnly: true);
        $this->check('Backups leave the server', config('homehelper.backups.disk') !== 'local', 'BACKUP_DISK=s3 (or another off-server disk)', warnOnly: true);
        $this->check('Configuration is cached', app()->configurationIsCached(), 'php artisan optimize', warnOnly: true);

        $this->table(['', 'Check', 'Detail'], $this->rows);

        $failed = count(array_filter($this->rows, fn ($r) => $r[0] === 'FAIL'));
        $warned = count(array_filter($this->rows, fn ($r) => $r[0] === 'warn'));

        if ($failed > 0) {
            $this->error("{$failed} failed, {$warned} warnings. Not safe for production.");

            return self::FAILURE;
        }
        $this->info("All required checks passed ({$warned} warnings).");

        return self::SUCCESS;
    }

    private function checkDemoAccounts(): void
    {
        try {
            $demo = User::query()
                ->whereIn('email', DemoSeeder::DEMO_EMAILS)
                ->orWhere('email', 'like', '%@homehelper.test')
                ->count();
            $this->check('No demo logins in the database', $demo === 0, "{$demo} account(s) with the password \"password\": delete them");
        } catch (Throwable $e) {
            $this->check('Database reachable', false, $e->getMessage());
        }
    }

    private function check(string $name, bool $ok, string $detail, bool $warnOnly = false): void
    {
        $this->rows[] = [$ok ? 'ok' : ($warnOnly ? 'warn' : 'FAIL'), $name, $ok ? '' : $detail];
    }
}
