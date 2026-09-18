<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use HiEvents\Assistant\Domain\AssistantUsageLimiter;
use HiEvents\Assistant\Exceptions\AssistantBudgetExceededException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Psr\Log\NullLogger;
use Tests\TestCase;

class AssistantUsageLimiterTest extends TestCase
{
    private function limiter(int $limit, ?CacheRepository $cache = null, int $monthly = 0, int $global = 0): AssistantUsageLimiter
    {
        return new AssistantUsageLimiter(
            cache: $cache ?? new CacheRepository(new ArrayStore()),
            config: new Config(['assistant' => ['daily_token_limit' => $limit, 'monthly_token_limit' => $monthly, 'global_daily_token_limit' => $global]]),
            logger: new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_usage_under_the_limit_is_allowed(): void
    {
        $limiter = $this->limiter(1000);
        $limiter->record(1, 400, 100);

        $limiter->assertWithinBudget(1);

        $this->assertSame(400 + 100 * 5, $limiter->usedToday(1));
    }

    public function test_the_account_is_blocked_once_the_budget_is_spent(): void
    {
        $limiter = $this->limiter(1000);
        $limiter->record(1, 900, 200);

        $this->expectException(AssistantBudgetExceededException::class);
        $limiter->assertWithinBudget(1);
    }

    public function test_budgets_are_tracked_per_account(): void
    {
        $limiter = $this->limiter(1000);
        $limiter->record(1, 900, 200);

        $limiter->assertWithinBudget(2);

        $this->assertSame(0, $limiter->usedToday(2));
    }

    public function test_the_counter_resets_on_the_next_day(): void
    {
        $cache = new CacheRepository(new ArrayStore());
        $limiter = $this->limiter(1000, $cache);

        Carbon::setTestNow(Carbon::parse('2026-09-11 23:00:00', 'UTC'));
        $limiter->record(1, 1200, 0);
        $this->assertSame(1200, $limiter->usedToday(1));

        Carbon::setTestNow(Carbon::parse('2026-09-12 00:05:00', 'UTC'));
        $limiter->assertWithinBudget(1);
        $this->assertSame(0, $limiter->usedToday(1));
    }

    public function test_a_zero_limit_disables_the_cap(): void
    {
        $limiter = $this->limiter(0);
        $limiter->record(1, 999999, 999999);

        $limiter->assertWithinBudget(1);

        $this->assertGreaterThan(0, $limiter->usedToday(1), 'spend is still counted (for assistant:usage) while no cap applies');
    }

    public function test_repeated_answers_accumulate(): void
    {
        $limiter = $this->limiter(1000);

        $limiter->record(1, 100, 50);
        $limiter->record(1, 100, 50);
        $limiter->record(1, 100, 50);

        $this->assertSame(3 * (100 + 50 * 5), $limiter->usedToday(1), 'output weighs 5x');
    }

    public function test_the_platform_wide_budget_stops_every_account(): void
    {
        $limiter = $this->limiter(0, global: 1000);

        $limiter->record(1, 600, 0);
        $limiter->record(2, 600, 0);

        $this->assertSame(1200, $limiter->usedToday(AssistantUsageLimiter::GLOBAL_ACCOUNT));
        $this->expectException(AssistantBudgetExceededException::class);
        $limiter->assertWithinBudget(3);
    }

    public function test_the_monthly_budget_outlives_the_day(): void
    {
        $limiter = $this->limiter(0, monthly: 1000);

        Carbon::setTestNow('2026-09-10 12:00:00');
        $limiter->record(1, 600, 0);
        Carbon::setTestNow('2026-09-11 12:00:00');
        $limiter->record(1, 600, 0);

        $this->assertSame(600, $limiter->usedToday(1));
        $this->assertSame(1200, $limiter->usedThisMonth(1));
        $this->expectException(AssistantBudgetExceededException::class);
        $limiter->assertWithinBudget(1);
    }

    public function test_cached_prefix_weighs_a_tenth_and_cache_writes_a_quarter_more(): void
    {
        $limiter = $this->limiter(100000);

        $limiter->record(1, 10, 0, cacheReadTokens: 10000, cacheWriteTokens: 1000);

        $this->assertSame(10 + 1000 + 1250, $limiter->usedToday(1));
    }
}
