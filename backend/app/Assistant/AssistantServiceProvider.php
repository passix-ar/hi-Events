<?php

declare(strict_types=1);

namespace HiEvents\Assistant;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Single integration point of the assistant module with the rest of the app.
 * Removing this provider from config/app.php (and this directory) leaves the
 * platform exactly as it was.
 *
 * The route is always registered so `php artisan optimize` caches it regardless
 * of the feature flag; the action answers 404 while the assistant is disabled.
 */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/assistant.php', 'assistant');
    }

    public function boot(): void
    {
        RateLimiter::for('assistant-chat', static function (Request $request) {
            $perMinute = (int)config('assistant.rate_limit_per_minute', 20);

            return Limit::perMinute($perMinute)->by(
                $request->user()?->getAuthIdentifier() ?? $request->ip()
            );
        });

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['api', 'auth:api', 'throttle:assistant-chat'])
            ->group(__DIR__ . '/routes.php');
    }
}
