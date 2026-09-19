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
            // The door scanner gets a budget of its own rather than the global one. The global cap
            // is per bare IP and every device at a door shares the venue's NAT, so the whole
            // entrance draws on a single budget: the roster re-downloads the full list every 60s in
            // pages of 250, which at our scale is 2-3 GET/min per phone but grows with the event.
            // 600 leaves room for a couple of hundred devices at the events we actually run, while
            // still refusing a scrape — these routes are unauthenticated and hand back the whole
            // roster with names, order and seat. Writes carry their own 'check-in' limiter below.
            // The thresholds where this would start refusing a real door are in
            // docs/check-in-rate-limit.md; read it before changing the number.
            if (str_starts_with($request->route()?->uri() ?? '', 'public/check-in-lists')) {
                return Limit::perMinute(600)->by($request->ip());
            }

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

        // Social sign in carries no email in the body, and an ID token cannot be guessed,
        // so this is not about brute force — it caps the signature-verification and
        // database work one origin can force. Roomier than password login because a
        // legitimate user may retry across several Google accounts.
        RateLimiter::for('auth-social', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Nonces are issued once per page load, so they get their own budget rather than
        // eating into the sign-in attempts above. Issuing one is cheap and grants nothing
        // on its own — it only counts once it returns inside a token Google signed.
        RateLimiter::for('auth-social-nonce', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
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

        // Door scanner writes. Keyed by list *and* origin: keyed by IP alone the staff of one
        // door share a NAT and throttle each other, and keyed by the short id alone anyone
        // holding the (shareable) link drains the door's own budget.
        //
        // Only POST/DELETE carry this. The three GETs are deliberately left on the global limiter:
        // the roster re-downloads the whole list every 60s in pages of 250, so a legitimate phone
        // generates ~40 GET/min at 10k attendees — that is the request a cap would strangle first.
        //
        // 300/min sizes the write path off scanning speed, not event size: a person scans one
        // ticket every 2-3s, so ~30 POST/min per device, and the budget covers ~10 devices behind
        // one NAT scanning flat out. See docs/check-in-rate-limit.md before changing it.
        RateLimiter::for('check-in', function (Request $request) {
            return Limit::perMinute(300)
                ->by('check-in:' . ($request->route('check_in_list_short_id') ?? '') . '|' . $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
