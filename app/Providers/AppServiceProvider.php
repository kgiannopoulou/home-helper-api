<?php

namespace App\Providers;

use App\Jobs\HouseholdJob;
use App\Models\Household;
use App\Models\User;
use App\Ops\Alerts;
use App\Weather\OpenMeteoWeatherProvider;
use App\Weather\WeatherProvider;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Events\TokenAuthenticated;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Plant care reads the weather through this interface (app/Weather)
        $this->app->bind(WeatherProvider::class, OpenMeteoWeatherProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
        $this->configureApiDocs();
        $this->configureLogContext();
        $this->configureQueueMonitoring();
        $this->configureOps();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Named limiters for routes/api.php. Signed-in requests are counted per
     * user, so a household on one Wi-Fi doesn't share a budget; the rest per IP.
     * A 429 carries Retry-After and the standard error body (bootstrap/app.php).
     */
    protected function configureRateLimits(): void
    {
        $byUser = fn (Request $request): string => $request->user()?->id
            ? 'user:'.$request->user()->id
            : 'ip:'.$request->ip();

        // Everything behind a token: generous, it only stops runaway clients
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($byUser($request)));

        // Phone login. Password guessing: per account and address, plus a ceiling
        // per address. ('login' is Fortify's, for the web login form.)
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(6)->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        // A phone syncs every few seconds at most, even when catching up
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(60)->by($byUser($request)));

        // Each invite sends an email
        RateLimiter::for('invites', fn (Request $request) => Limit::perHour(20)->by($byUser($request)));

        // Each assistant message is a paid model call (and up to 8 tool rounds).
        // There is also a daily cap per person (homehelper.ai.daily_messages).
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(10)->by('ai:'.$byUser($request)));

        // Documents are parsed (PDF text) and embedded on upload
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(10)->by('uploads:'.$byUser($request)));

        // The health check queries the database, Redis and the disk: enough for
        // a monitor polling every few seconds, not for someone hammering it
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(30)->by('health:'.$request->ip()));
    }

    /**
     * OpenAPI document generated from the routes, Form Requests and
     * Resources by Scramble: browse it at /docs/api (local only), or read the
     * exported docs/openapi.json (`composer docs:api`).
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });
    }

    /**
     * Every log line of a request carries request_id (AssignRequestId),
     * user_id and household_id. The Context goes along into queued jobs too, so
     * a job's log lines can be traced back to the request that queued it.
     */
    protected function configureLogContext(): void
    {
        Event::listen(Authenticated::class, fn (Authenticated $e) => Context::add('user_id', $e->user->getAuthIdentifier()));
        Event::listen(TokenAuthenticated::class, fn (TokenAuthenticated $e) => Context::add('user_id', $e->token->tokenable_id));

        Route::bind('household', function (string $value) {
            Context::add('household_id', $value);

            return Household::findOrFail($value);
        });

        // One slow query, with its SQL (bindings left out: they can be personal data)
        $slow = (int) config('homehelper.ops.slow_query_ms');
        if ($slow > 0) {
            DB::listen(function (QueryExecuted $query) use ($slow) {
                if ($query->time >= $slow) {
                    Log::warning('Slow query', [
                        'ms' => round($query->time, 1),
                        'sql' => Str::limit($query->sql, 2000),
                        'connection' => $query->connectionName,
                    ]);
                }
            });
        }
    }

    /**
     * Jobs that failed for good and queues that keep growing raise an ops
     * alert (App\Ops\Alerts). HouseholdJob raises its own in failed(), with
     * the household; this covers everything else (queued mail, notifications).
     * queue:monitor runs every minute (routes/console.php) and fires QueueBusy.
     */
    protected function configureQueueMonitoring(): void
    {
        Queue::failing(function (JobFailed $event) {
            $name = $event->job->resolveName();
            if (is_subclass_of($name, HouseholdJob::class)) {
                return;
            }
            Alerts::raise('job-failed:'.class_basename($name), class_basename($name).' failed', [
                'queue' => $event->job->getQueue(),
                'job_id' => $event->job->getJobId(),
                'error' => $event->exception->getMessage(),
            ]);
        });

        Event::listen(QueueBusy::class, fn (QueueBusy $e) => Alerts::raise(
            "queue-busy:{$e->connectionName}:{$e->queue}",
            "Queue {$e->queue} has {$e->size} jobs waiting",
            ['connection' => $e->connectionName, 'queue' => $e->queue, 'size' => $e->size],
        ));
    }

    /**
     * Who may see /system/health: the emails in OPS_ADMIN_EMAILS.
     */
    protected function configureOps(): void
    {
        Gate::define('viewSystemHealth', fn (User $user) => in_array(
            Str::lower($user->email),
            array_map(Str::lower(...), config('homehelper.ops.admins')),
            true,
        ));
    }
}
