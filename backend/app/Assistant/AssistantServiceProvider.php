<?php

declare(strict_types=1);

namespace HiEvents\Assistant;

use HiEvents\Assistant\Console\AssistantUsageCommand;
use HiEvents\Assistant\Console\EventAlertsCommand;
use HiEvents\Assistant\Console\SyncHelpDocsCommand;
use HiEvents\Assistant\Domain\Attachments\AssistantAttachmentStore;
use HiEvents\Assistant\Domain\HelpDocs\HelpDocsIndex;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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

        $this->app->singleton(AssistantAttachmentStore::class, static fn(): AssistantAttachmentStore => new AssistantAttachmentStore(
            disk: Storage::disk((string)config('filesystems.private')),
        ));

        $this->app->singleton(HelpDocsIndex::class, static fn($app): HelpDocsIndex => new HelpDocsIndex(
            cache: $app->make(CacheRepository::class),
            docsPath: (string)config('assistant.help_docs.path'),
            baseUrl: (string)config('assistant.help_docs.base_url'),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/Resources/views', 'assistant');

        // Proactive sales alerts, off unless AI_ASSISTANT_ALERTS_ENABLED. Registered
        // here rather than in Console/Kernel so the module stays self-contained.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            if (config('assistant.alerts.enabled')) {
                $schedule->command('assistant:event-alerts')->dailyAt('12:00')->withoutOverlapping();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([SyncHelpDocsCommand::class, EventAlertsCommand::class, AssistantUsageCommand::class]);
        }

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
