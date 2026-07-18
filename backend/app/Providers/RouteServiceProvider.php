<?php

// Modified by Passix on 2026-05-25: Added rate limiting for auth and public contact endpoints.

namespace HiEvents\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('app.api_rate_limit_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Login: cap brute-force per (account + source), plus a coarser per-IP cap
        // to blunt password spraying across many accounts from one origin.
        RateLimiter::for('auth', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('auth:'.$email.'|'.$request->ip()),
                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
            ];
        });

        // Registration abuse is driven by origin, not by the (attacker-chosen) email.
        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Password reset: protect a single victim from mail bombing (per email/hour)
        // and cap the overall mail volume from one origin (per IP/minute).
        RateLimiter::for('auth-forgot', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perHour(5)->by('forgot-email:'.$email),
                Limit::perMinute(5)->by('forgot-ip:'.$request->ip()),
            ];
        });

        // Token endpoints (confirm-email, reset, invitation): no email in the body,
        // so key on origin only. Long, high-entropy tokens make this defence-in-depth.
        RateLimiter::for('auth-token', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('self-service-email', function (Request $request) {
            return Limit::perHour(20)->by($request->route('order_short_id') ?? $request->ip());
        });

        RateLimiter::for('self-service-edit', function (Request $request) {
            return Limit::perHour(20)->by($request->route('order_short_id') ?? $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
