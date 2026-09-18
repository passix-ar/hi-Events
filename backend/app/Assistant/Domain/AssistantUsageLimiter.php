<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\Assistant\Exceptions\AssistantBudgetExceededException;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The per-user rate limit caps how fast an account can ask; this caps how much
 * gets spent. Three budgets, all in the same "token equivalents" (see record()):
 *
 *  - per account per day    (assistant.daily_token_limit)
 *  - per account per month  (assistant.monthly_token_limit)
 *  - whole platform per day (assistant.global_daily_token_limit) - the one that
 *    protects the bill no matter how many accounts there are.
 *
 * Tokens are counted after each answer, so a budget can be overshot by at most
 * one conversation - which is the point: never refuse a question because of a
 * token estimate made before the model ran. A limit of 0 disables that budget.
 */
readonly class AssistantUsageLimiter
{
    public const GLOBAL_ACCOUNT = 0;

    public function __construct(
        private Cache           $cache,
        private Config          $config,
        private LoggerInterface $logger,
    )
    {
    }

    /**
     * @throws AssistantBudgetExceededException
     */
    public function assertWithinBudget(int $accountId): void
    {
        $checks = [
            ['scope' => 'account_daily', 'limit' => $this->limit('daily_token_limit'), 'used' => $this->usedToday($accountId), 'message' => __('You have reached today\'s assistant usage limit. Please try again tomorrow.')],
            ['scope' => 'account_monthly', 'limit' => $this->limit('monthly_token_limit'), 'used' => $this->usedThisMonth($accountId), 'message' => __('You have reached this month\'s assistant usage limit.')],
            ['scope' => 'platform_daily', 'limit' => $this->limit('global_daily_token_limit'), 'used' => $this->usedToday(self::GLOBAL_ACCOUNT), 'message' => __('The assistant is very busy today. Please try again tomorrow.')],
        ];

        foreach ($checks as $check) {
            if ($check['limit'] <= 0 || $check['used'] < $check['limit']) {
                continue;
            }

            $this->logger->warning('assistant.budget.exceeded', [
                'scope' => $check['scope'],
                'account_id' => $accountId,
                'tokens_used' => $check['used'],
                'limit' => $check['limit'],
            ]);

            throw new AssistantBudgetExceededException($check['message']);
        }
    }

    /**
     * The budget is in "token equivalents" weighted by what Anthropic charges:
     * a cached read costs a tenth of a fresh input token, a cache write 1.25x,
     * output about 5x. Counting raw input alone made the budget meaningless,
     * since almost everything the assistant sends is a cached prefix.
     */
    public function record(int $accountId, int $inputTokens, int $outputTokens, int $cacheReadTokens = 0, int $cacheWriteTokens = 0): void
    {
        $tokens = (int)max(
            $inputTokens + $outputTokens * 5 + (int)round($cacheReadTokens * 0.1) + (int)round($cacheWriteTokens * 1.25),
            0,
        );

        if ($tokens === 0) {
            return;
        }

        $this->bump($this->dayKey($accountId), $tokens, $this->secondsUntilTomorrow());
        $this->bump($this->monthKey($accountId), $tokens, $this->secondsUntilNextMonth());
        $this->bump($this->dayKey(self::GLOBAL_ACCOUNT), $tokens, $this->secondsUntilTomorrow());
        $this->bump($this->monthKey(self::GLOBAL_ACCOUNT), $tokens, $this->secondsUntilNextMonth());
    }

    public function usedToday(int $accountId): int
    {
        return (int)$this->cache->get($this->dayKey($accountId), 0);
    }

    public function usedThisMonth(int $accountId): int
    {
        return (int)$this->cache->get($this->monthKey($accountId), 0);
    }

    /** Clears today's and this month's counters for one account (or the platform with GLOBAL_ACCOUNT). */
    public function reset(int $accountId): void
    {
        $this->cache->forget($this->dayKey($accountId));
        $this->cache->forget($this->monthKey($accountId));
    }

    /** @return array{daily: int, monthly: int, global_daily: int} */
    public function limits(): array
    {
        return [
            'daily' => $this->limit('daily_token_limit'),
            'monthly' => $this->limit('monthly_token_limit'),
            'global_daily' => $this->limit('global_daily_token_limit'),
        ];
    }

    private function bump(string $key, int $tokens, int $ttl): void
    {
        // add() seeds the counter with the window's TTL; increment() keeps it.
        if (!$this->cache->add($key, $tokens, $ttl)) {
            $this->cache->increment($key, $tokens);
        }
    }

    private function limit(string $name): int
    {
        return (int)$this->config->get('assistant.' . $name, 0);
    }

    private function dayKey(int $accountId): string
    {
        return sprintf('assistant.usage.%d.%s', $accountId, Carbon::now('UTC')->toDateString());
    }

    private function monthKey(int $accountId): string
    {
        return sprintf('assistant.usage.%d.%s', $accountId, Carbon::now('UTC')->format('Y-m'));
    }

    private function secondsUntilTomorrow(): int
    {
        $now = Carbon::now('UTC');

        return max((int)$now->copy()->addDay()->startOfDay()->diffInSeconds($now, absolute: true), 60);
    }

    private function secondsUntilNextMonth(): int
    {
        $now = Carbon::now('UTC');

        return max((int)$now->copy()->addMonthNoOverflow()->startOfMonth()->diffInSeconds($now, absolute: true), 60);
    }
}
