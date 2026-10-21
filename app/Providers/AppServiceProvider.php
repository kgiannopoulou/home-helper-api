<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimits();
        $this->configureApiDocs();
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
}
