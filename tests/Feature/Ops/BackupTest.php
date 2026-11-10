<?php

use App\Notifications\OpsAlert;
use App\Ops\Backups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

/*
 * db:backup and db:restore-test against the real MySQL test database, with
 * the real mysqldump and mysql clients (CI's runner has them; locally they
 * run in the Docker image described in docs/backups.md). Without the
 * clients these tests are skipped, not faked: a faked mysqldump proves nothing.
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    config(['homehelper.ops.alert_email' => 'ops@example.com']);
});

function hasMysqlClients(): bool
{
    $finder = new ExecutableFinder;

    return $finder->find(config('homehelper.backups.mysqldump')) !== null
        && $finder->find(config('homehelper.backups.mysql')) !== null;
}

test('back up, then restore into a scratch database with every row count matching', function () {
    $this->artisan('db:backup')->assertSuccessful();

    $backups = Backups::all();
    expect($backups)->toHaveCount(1);
    $manifest = $backups[0];
    expect($manifest['tables'])->toHaveKeys(['households', 'expenses', 'migrations', 'plants'])
        ->and($manifest['tables']['migrations'])->toBeGreaterThan(30)
        ->and(Storage::disk('local')->exists('backups/'.$manifest['file']))->toBeTrue()
        ->and(hash('sha256', Storage::disk('local')->get('backups/'.$manifest['file'])))->toBe($manifest['sha256']);

    $this->artisan('db:restore-test')->assertSuccessful();

    $result = Backups::lastRestoreTest();
    expect($result['status'])->toBe('passed')
        ->and($result['tables'])->toBe(count($manifest['tables']))
        ->and($result['mismatches'])->toBe([]);

    // The scratch database is dropped again
    $restoreDb = config('homehelper.backups.restore_database');
    expect(DB::select('select schema_name from information_schema.schemata where schema_name = ?', [$restoreDb]))->toBe([]);
    Notification::assertNothingSent();
})->skip(fn () => ! hasMysqlClients(), 'needs the mysqldump and mysql clients');

test('a corrupted backup fails the restore test and raises an alert', function () {
    $this->artisan('db:backup')->assertSuccessful();
    $file = 'backups/'.Backups::latest()['file'];
    Storage::disk('local')->put($file, gzencode('-- not the dump'));

    $this->artisan('db:restore-test')->assertFailed();

    expect(Backups::lastRestoreTest()['status'])->toBe('failed')
        ->and(Backups::lastRestoreTest()['error'])->toContain('sha256');
    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $n) => $n->title === 'Backup restore test failed');
})->skip(fn () => ! hasMysqlClients(), 'needs the mysqldump and mysql clients');

test('only the newest BACKUP_KEEP backups are kept', function () {
    config(['homehelper.backups.keep' => 2]);

    foreach (['20261101-030000', '20261102-030000', '20261103-030000'] as $i => $stamp) {
        $this->travelTo(now()->setDate(2026, 11, $i + 1)->setTime(3, 0));
        $this->artisan('db:backup')->assertSuccessful();
    }

    expect(array_column(Backups::all(), 'name'))->toBe(['home-helper-20261103-030000', 'home-helper-20261102-030000'])
        ->and(Storage::disk('local')->exists('backups/home-helper-20261101-030000.sql.gz'))->toBeFalse();
})->skip(fn () => ! hasMysqlClients(), 'needs the mysqldump and mysql clients');

test('a mysqldump that fails raises an alert', function () {
    config(['homehelper.backups.mysqldump' => 'definitely-not-mysqldump']);

    $this->artisan('db:backup')->assertFailed();

    expect(Backups::all())->toBe([]);
    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $n) => $n->title === 'Database backup failed');
});

test('it refuses to restore over the live database', function () {
    config(['homehelper.backups.restore_database' => config('database.connections.mysql.database')]);

    $this->artisan('db:restore-test')->assertFailed();

    expect(Backups::lastRestoreTest()['error'])->toContain('separate scratch database');

    // ...and leaves it alone (an early version's cleanup dropped it)
    $live = config('database.connections.mysql.database');
    expect(DB::select('select schema_name from information_schema.schemata where schema_name = ?', [$live]))->toHaveCount(1);
});

test('no backups yet: the restore test says so', function () {
    $this->artisan('db:restore-test')->assertFailed();

    expect(Backups::lastRestoreTest()['error'])->toContain('no backups yet');
});
