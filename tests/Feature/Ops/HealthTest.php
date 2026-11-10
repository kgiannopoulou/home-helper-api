<?php

use App\Enums\JobStatus;
use App\Models\Household;
use App\Models\JobRun;
use App\Models\User;
use App\Ops\Alerts;
use App\Ops\SystemHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * GET /api/health (uptime monitors) and /system/health (admins).
 * Redis is mocked here: the checks are about what the app does with its
 * answers, and CI's Redis isn't needed for that.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['homehelper.ops.admins' => ['ops@example.com']]);

    $redis = Mockery::mock();
    $redis->shouldReceive('command')->with('ping')->andReturn('PONG');
    $redis->shouldReceive('command')->with('info', ['memory'])->andReturn(['used_memory_human' => '1.2M']);
    Redis::shouldReceive('connection')->andReturn($redis);

    // The queue check reads sizes through the redis queue connection
    $queue = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $queue->shouldReceive('size')->andReturn(0);
    Queue::shouldReceive('connection')->with('redis')->andReturn($queue);
});

test('anyone gets the status of each check, without details', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonStructure(['status', 'checked_at', 'checks' => ['database' => ['status'], 'redis', 'queues', 'failed_jobs', 'scheduler', 'household_jobs', 'backups', 'disk']])
        ->assertJsonMissingPath('checks.database.summary')
        ->assertJsonMissingPath('app')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok');
});

test('an admin token gets the details too', function () {
    Sanctum::actingAs(User::factory()->create(['email' => 'ops@example.com']));

    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonStructure(['checks' => ['database' => ['status', 'summary', 'ms', 'details']], 'app' => ['environment', 'php', 'laravel']]);
});

test('a token that is not an admin\'s gets the short version', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/health')->assertOk()->assertJsonMissingPath('app');
});

test('no scheduler heartbeat and no backup: degraded, and says what to run', function () {
    $report = app(SystemHealth::class)->run();

    expect($report['status'])->toBe('degraded')
        ->and($report['checks']['scheduler']['status'])->toBe('fail')
        ->and($report['checks']['scheduler']['summary'])->toContain('schedule:work')
        ->and($report['checks']['backups']['status'])->toBe('fail')
        ->and($report['checks']['backups']['summary'])->toContain('db:backup');
});

test('a fresh heartbeat, a recent backup with a passed restore test, jobs on time: those checks are ok', function () {
    Cache::forever(SystemHealth::SCHEDULER_HEARTBEAT, now()->subMinute()->toIso8601String());
    Storage::disk('local')->put('backups/home-helper-20261110-030000.json', json_encode([
        'name' => 'home-helper-20261110-030000', 'file' => 'home-helper-20261110-030000.sql.gz',
        'created_at' => now()->subHours(3)->toIso8601String(), 'bytes' => 204800, 'sha256' => 'x', 'tables' => [], 'rows' => 0,
    ]));
    Storage::disk('local')->put('backups/restore-test.json', json_encode(['at' => now()->subHour()->toIso8601String(), 'status' => 'passed', 'backup' => 'home-helper-20261110-030000']));

    $home = Household::factory()->create();
    foreach (['CheckPlantCare', 'LearnChoreFrequencies', 'ApplyRecurringBills', 'PrepareShoppingList', 'BudgetAlert', 'WeeklySummary'] as $job) {
        JobRun::forceCreate(['household_id' => $home->id, 'job' => $job, 'status' => JobStatus::Succeeded, 'for_date' => today(), 'started_at' => now()->subMinutes(30)]);
    }

    $checks = app(SystemHealth::class)->run()['checks'];

    expect($checks['scheduler']['status'])->toBe('ok')
        ->and($checks['backups']['status'])->toBe('ok')
        ->and($checks['backups']['summary'])->toContain('restore test passed')
        ->and($checks['household_jobs']['status'])->toBe('ok');
});

test('a household job that failed today is flagged, as are failed queue jobs', function () {
    $home = Household::factory()->create();
    JobRun::forceCreate(['household_id' => $home->id, 'job' => 'BudgetAlert', 'status' => JobStatus::Failed, 'for_date' => today(), 'started_at' => now()->subHour(), 'error' => 'boom']);
    DB::table('failed_jobs')->insert([
        'uuid' => 'f-1', 'connection' => 'redis', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Notifications\\HouseholdInvite']),
        'exception' => "RuntimeException: SMTP refused\n#0 trace", 'failed_at' => now(),
    ]);

    $checks = app(SystemHealth::class)->run()['checks'];

    expect($checks['household_jobs']['status'])->toBe('warn')
        ->and($checks['household_jobs']['details']['jobs']['BudgetAlert']['failed_24h'])->toBe(1)
        ->and($checks['failed_jobs']['status'])->toBe('warn')
        ->and($checks['failed_jobs']['details']['latest'][0])->toMatchArray(['job' => 'HouseholdInvite', 'error' => 'RuntimeException: SMTP refused']);
});

test('Redis down is a failed check, not a failed page', function () {
    $redis = Mockery::mock();
    $redis->shouldReceive('command')->andThrow(new RuntimeException('Connection refused'));
    Redis::swap(Mockery::mock()->shouldReceive('connection')->andReturn($redis)->getMock());

    $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.redis.status', 'fail')->assertJsonPath('status', 'degraded');
});

test('the page is for admins only', function () {
    $this->get('/system/health')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/system/health')->assertForbidden();
});

test('an admin sees every check, the alerts and the server facts', function () {
    Alerts::raise('job-failed:BudgetAlert', 'BudgetAlert failed after 3 tries');

    $this->actingAs(User::factory()->create(['email' => 'OPS@example.com']))
        ->get('/system/health')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('system-health')
            ->has('health.checks', 8)
            ->where('alerts.0.title', 'BudgetAlert failed after 3 tries')
            ->has('app.php')
            ->where('auth.can.viewSystemHealth', true));
});

test('the health endpoint has its own rate limit', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/health')->assertOk();
    }
    $this->getJson('/api/health')->assertTooManyRequests()->assertHeader('Retry-After');
});
