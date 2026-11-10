<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

/*
 * app:production-check and the demo data guard (docs/deployment.md).
 */

/** A configuration that should pass every required check */
function safeProductionConfig(): void
{
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://home.example.com',
        'database.connections.mysql.password' => 'a-long-random-password',
        'database.connections.mysql.username' => 'home_helper',
        'mail.default' => 'smtp',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.secure' => true,
        'sanctum.expiration' => 129600,
    ]);
}

test('a safe configuration passes', function () {
    safeProductionConfig();

    $this->artisan('app:production-check')->assertSuccessful()->expectsOutputToContain('All required checks passed');
});

test('the local defaults fail', function (string $key, mixed $value, string $check) {
    safeProductionConfig();
    config([$key => $value]);

    $this->artisan('app:production-check')->assertFailed()->expectsOutputToContain($check);
})->with([
    'debug on' => ['app.debug', true, 'APP_DEBUG is off'],
    'the Sail password' => ['database.connections.mysql.password', 'password', 'Database password is not a default'],
    'root' => ['database.connections.mysql.username', 'root', 'Database user is not root'],
    'mail to the log' => ['mail.default', 'log', 'Mail is really sent'],
    'sync queue' => ['queue.default', 'sync', 'Queue is not sync'],
    'http' => ['app.url', 'http://localhost:8000', 'APP_URL uses https'],
    'tokens forever' => ['sanctum.expiration', null, 'API tokens expire'],
]);

test('demo logins in the database fail the check', function () {
    safeProductionConfig();
    User::factory()->create(['email' => DemoSeeder::DEMO_EMAILS[0]]);

    $this->artisan('app:production-check')->assertFailed()->expectsOutputToContain('No demo logins in the database');
});

test('the demo seeder refuses to run in production, and db:seed skips it', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'refuses to run in production');

    (new DatabaseSeeder)->run();
    expect(User::count())->toBe(0);
});
