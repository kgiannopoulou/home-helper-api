<?php

use App\Jobs\BudgetAlert;
use App\Models\Household;
use App\Notifications\OpsAlert;
use App\Ops\Alerts;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
 * Ops alerts (App\Ops\Alerts): failed jobs, busy queues and server errors are
 * logged, listed for the health page, and emailed once per cooldown.
 */

beforeEach(function () {
    Notification::fake();
    config(['homehelper.ops.alert_email' => 'ops@example.com', 'homehelper.ops.alert_cooldown_minutes' => 15]);
});

test('an alert is emailed once per cooldown, and every one is listed', function () {
    expect(Alerts::raise('backup-failed', 'Database backup failed'))->toBeTrue()
        ->and(Alerts::raise('backup-failed', 'Database backup failed'))->toBeFalse();

    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
    expect(Alerts::recent())->toHaveCount(2)
        ->and(Alerts::recent()[0]['key'])->toBe('backup-failed');

    $this->travel(16)->minutes();
    expect(Alerts::raise('backup-failed', 'Database backup failed'))->toBeTrue();
    Notification::assertSentOnDemandTimes(OpsAlert::class, 2);
});

test('without OPS_ALERT_EMAIL it is only logged and listed', function () {
    config(['homehelper.ops.alert_email' => null]);

    expect(Alerts::raise('x', 'Something'))->toBeFalse();
    Notification::assertNothingSent();
    expect(Alerts::recent())->toHaveCount(1);
});

test('a household job that runs out of tries raises one alert for all households', function () {
    $job = new BudgetAlert(Household::factory()->create(), '2026-11-10');
    $job->failed(new RuntimeException('Expo is down'));
    (new BudgetAlert(Household::factory()->create(), '2026-11-10'))->failed(new RuntimeException('Expo is down'));

    Notification::assertSentOnDemandTimes(OpsAlert::class, 1);
    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $n) => $n->title === 'BudgetAlert failed after 3 tries'
        && $n->context['error'] === 'Expo is down');
});

test('household jobs go on their own queue', function () {
    expect((new BudgetAlert)->queue)->toBe('households');
});

test('any other failed job raises an alert too', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Notifications\\HouseholdInvite');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('getJobId')->andReturn('42');

    event(new JobFailed('redis', $job, new RuntimeException('SMTP refused')));

    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $n) => $n->title === 'HouseholdInvite failed');
});

test('a busy queue raises an alert', function () {
    event(new QueueBusy('redis', 'households', 250));

    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $n) => $n->title === 'Queue households has 250 jobs waiting');
});

test('a server error raises an alert, and the API answer hides the message', function () {
    config(['app.debug' => false]);
    Route::get('api/explode', fn () => throw new LogicException('secret internals'));

    $response = $this->getJson('/api/explode')
        ->assertStatus(500)
        ->assertJsonPath('code', 'server_error')
        ->assertJsonStructure(['message', 'code', 'request_id']);

    expect($response->getContent())->not->toContain('secret internals');
    Notification::assertSentOnDemand(OpsAlert::class, fn (OpsAlert $n) => str_contains($n->title, 'LogicException: secret internals'));
});

test('a 4xx is not an alert', function () {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/does-not-exist')->assertNotFound()->assertJsonPath('code', 'not_found');

    Notification::assertNothingSent();
    expect(Alerts::recent())->toBe([]);
});
