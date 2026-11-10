<?php

namespace App\Ops;

use App\Enums\JobStatus;
use App\Models\JobRun;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything the app needs to be working, checked in one place: database,
 * Redis, the queues and failed jobs, the scheduler, the household jobs,
 * backups and disk space. Read by GET /api/health (for an uptime monitor)
 * and the /system/health page (for an admin). docs/operations.md.
 *
 * Each check is ok, warn (works, but look at it) or fail (broken).
 *
 * @phpstan-type CheckResult array{0: string, 1: string, 2?: array<string, mixed>}
 */
class SystemHealth
{
    public const SCHEDULER_HEARTBEAT = 'health:scheduler-heartbeat';

    /** The scheduled household jobs and how often each should run, in hours */
    private const JOB_EVERY_HOURS = [
        'CheckPlantCare' => 1,
        'LearnChoreFrequencies' => 24,
        'ApplyRecurringBills' => 24,
        'PrepareShoppingList' => 24,
        'BudgetAlert' => 24,
        'WeeklySummary' => 24 * 7,
    ];

    /**
     * @return array{status: string, checked_at: string, checks: array<string, array{status: string, summary: string, ms: float, details: array<string, mixed>}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->check(fn () => $this->database()),
            'redis' => $this->check(fn () => $this->redis()),
            'queues' => $this->check(fn () => $this->queues()),
            'failed_jobs' => $this->check(fn () => $this->failedJobs()),
            'scheduler' => $this->check(fn () => $this->scheduler()),
            'household_jobs' => $this->check(fn () => $this->householdJobs()),
            'backups' => $this->check(fn () => $this->backups()),
            'disk' => $this->check(fn () => $this->disk()),
        ];

        $statuses = array_column($checks, 'status');
        $status = match (true) {
            $checks['database']['status'] === 'fail' => 'down',
            in_array('fail', $statuses, true), in_array('warn', $statuses, true) => 'degraded',
            default => 'ok',
        };

        return ['status' => $status, 'checked_at' => CarbonImmutable::now()->toIso8601String(), 'checks' => $checks];
    }

    /**
     * Facts about the running app, for the admin page
     *
     * @return array<string, mixed>
     */
    public function app(): array
    {
        return [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'php' => PHP_VERSION,
            'laravel' => Application::VERSION,
            'version' => config('app.version') ?: $this->gitCommit(),
            'timezone' => config('homehelper.timezone'),
            'queue' => config('queue.default'),
            'cache' => config('cache.default'),
            'config_cached' => app()->configurationIsCached(),
        ];
    }

    /**
     * Runs one check and times it. An exception is a failed check, never a failed page.
     *
     * @param  Closure(): CheckResult  $check
     * @return array{status: string, summary: string, ms: float, details: array<string, mixed>}
     */
    private function check(Closure $check): array
    {
        $start = hrtime(true);
        try {
            [$status, $summary, $details] = $check() + [2 => []];
        } catch (Throwable $e) {
            [$status, $summary, $details] = ['fail', class_basename($e).': '.Str::limit($e->getMessage(), 200), []];
        }

        return [
            'status' => $status,
            'summary' => $summary,
            'ms' => round((hrtime(true) - $start) / 1e6, 1),
            'details' => $details,
        ];
    }

    /** @return CheckResult */
    private function database(): array
    {
        $db = DB::connection();
        $db->select('select 1');
        $version = (string) $db->scalar('select version()');

        return ['ok', "{$db->getDriverName()} {$version}, database {$db->getDatabaseName()}", ['version' => $version]];
    }

    /** @return CheckResult */
    private function redis(): array
    {
        $pong = Redis::connection()->command('ping');
        $info = Redis::connection()->command('info', ['memory']);
        $memory = is_array($info) ? ($info['used_memory_human'] ?? $info['Memory']['used_memory_human'] ?? null) : null;

        $ok = $pong === true || str_contains(strtoupper((string) $pong), 'PONG');

        return [$ok ? 'ok' : 'fail', 'ping answered'.($memory ? ", {$memory} used" : ''), ['memory' => $memory]];
    }

    /** @return CheckResult */
    private function queues(): array
    {
        $max = (int) config('homehelper.queues.max_waiting');
        $sizes = [];
        foreach (config('homehelper.queues.monitored') as $queue) {
            $sizes[$queue] = Queue::connection('redis')->size($queue);
        }
        $busy = array_filter($sizes, fn (int $n) => $n > $max);
        $summary = collect($sizes)->map(fn ($n, $q) => "{$q}: {$n} waiting")->implode(', ');

        if (config('queue.default') === 'sync') {
            return ['warn', 'QUEUE_CONNECTION=sync: jobs run inside the request. '.$summary, ['sizes' => $sizes]];
        }

        return [$busy ? 'warn' : 'ok', $summary.($busy ? " (more than {$max})" : ''), ['sizes' => $sizes, 'max_waiting' => $max]];
    }

    /** @return CheckResult */
    private function failedJobs(): array
    {
        $table = config('queue.failed.table', 'failed_jobs');
        $total = DB::table($table)->count();
        $recent = DB::table($table)->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count();
        $latest = DB::table($table)->latest('failed_at')->limit(5)->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'job' => class_basename((string) (json_decode($row->payload, true)['displayName'] ?? '?')),
                'error' => Str::limit(strtok((string) $row->exception, "\n") ?: '', 200),
                'failed_at' => $row->failed_at,
            ])->all();

        return [
            $recent > 0 ? 'warn' : 'ok',
            $recent > 0 ? "{$recent} failed in the last 24 hours ({$total} kept). Retry: php artisan queue:retry all" : "none in the last 24 hours ({$total} kept)",
            ['total' => $total, 'last_24h' => $recent, 'latest' => $latest],
        ];
    }

    /** @return CheckResult */
    private function scheduler(): array
    {
        $beat = Cache::get(self::SCHEDULER_HEARTBEAT);
        if (! $beat) {
            return ['fail', 'never ran: start `php artisan schedule:work`, or the cron line on a server'];
        }
        $age = (int) CarbonImmutable::parse($beat)->diffInMinutes(CarbonImmutable::now(), true);
        $stale = (int) config('homehelper.health.scheduler_stale_minutes');

        return [$age > $stale ? 'fail' : 'ok', "last ran {$age} min ago", ['last_run' => $beat]];
    }

    /** @return CheckResult */
    private function householdJobs(): array
    {
        $now = CarbonImmutable::now();
        $jobs = [];
        $late = [];

        foreach (self::JOB_EVERY_HOURS as $job => $hours) {
            $last = JobRun::query()->where('job', $job)->latest('started_at')->first(['status', 'started_at', 'error']);
            $failed = JobRun::query()->where('job', $job)->where('status', JobStatus::Failed)
                ->where('started_at', '>=', $now->subDay())->count();
            // Late if it hasn't run in twice its interval (plus an hour of slack)
            $isLate = $last === null || $last->started_at->lt($now->subHours($hours * 2 + 1));
            if ($isLate || $failed > 0) {
                $late[] = $job;
            }
            $jobs[$job] = [
                'every_hours' => $hours,
                'last_status' => $last?->status->value,
                'last_started_at' => $last?->started_at->toIso8601String(),
                'failed_24h' => $failed,
                'late' => $isLate,
            ];
        }

        return [
            $late ? 'warn' : 'ok',
            $late ? 'late or failing: '.implode(', ', $late) : 'every job ran on time',
            ['jobs' => $jobs],
        ];
    }

    /** @return CheckResult */
    private function backups(): array
    {
        $latest = Backups::latest();
        $restore = Backups::lastRestoreTest();
        $details = ['latest' => $latest ? Arr::except($latest, ['tables']) : null, 'restore_test' => $restore, 'count' => count(Backups::all())];

        if (! $latest) {
            return ['fail', 'no backup yet: php artisan db:backup', $details];
        }
        $hours = (int) CarbonImmutable::parse($latest['created_at'])->diffInHours(CarbonImmutable::now(), true);
        $tooOld = $hours > (int) config('homehelper.backups.max_age_hours');
        $restoreOk = ($restore['status'] ?? null) === 'passed';

        $summary = "newest {$hours} h old (".number_format($latest['bytes'] / 1024, 0).' KB)'
            .'; restore test '.($restore ? "{$restore['status']} ".CarbonImmutable::parse($restore['at'])->diffForHumans() : 'never run');

        return [$tooOld ? 'fail' : ($restoreOk ? 'ok' : 'warn'), $summary, $details];
    }

    /** @return CheckResult */
    private function disk(): array
    {
        $free = (float) disk_free_space(storage_path());
        $total = (float) disk_total_space(storage_path());
        $freeMb = (int) ($free / 1024 / 1024);
        $min = (int) config('homehelper.health.disk_min_free_mb');

        return [
            $freeMb < $min ? 'fail' : 'ok',
            number_format($freeMb / 1024, 1).' GB free of '.number_format($total / 1024 / 1024 / 1024, 1).' GB',
            ['free_mb' => $freeMb, 'min_free_mb' => $min],
        ];
    }

    private function gitCommit(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $ref = trim((string) file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $file = base_path('.git/'.substr($ref, 5));
            $ref = is_file($file) ? trim((string) file_get_contents($file)) : null;
        }

        return $ref ? substr($ref, 0, 7) : null;
    }
}
