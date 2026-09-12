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
    private function limiter(int $limit, ?CacheRepository $cache = null): AssistantUsageLimiter
    {
        return new AssistantUsageLimiter(
            cache: $cache ?? new CacheRepository(new ArrayStore()),
            config: new Config(['assistant' => ['daily_token_limit' => $limit]]),
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

        $this->assertSame(500, $limiter->usedToday(1));
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

        $this->assertSame(0, $limiter->usedToday(1), 'nothing is counted while the cap is off');
    }

    public function test_repeated_answers_accumulate(): void
    {
        $limiter = $this->limiter(1000);

        $limiter->record(1, 100, 50);
        $limiter->record(1, 100, 50);
        $limiter->record(1, 100, 50);

        $this->assertSame(450, $limiter->usedToday(1));
    }
}
