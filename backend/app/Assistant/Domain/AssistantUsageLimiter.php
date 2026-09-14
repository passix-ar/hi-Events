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
 * an account can spend in a day. Without it, one logged-in user holding the send
 * button is an open tap on the API bill.
 *
 * Tokens are counted after each answer, so the budget can be overshot by at most
 * one conversation - which is the point: never refuse a question because of a
 * token estimate made before the model ran.
 */
readonly class AssistantUsageLimiter
{
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
        $limit = $this->dailyTokenLimit();

        if ($limit <= 0) {
            return;
        }

        $used = (int)$this->cache->get($this->key($accountId), 0);

        if ($used < $limit) {
            return;
        }

        $this->logger->warning('assistant.budget.exceeded', [
            'account_id' => $accountId,
            'tokens_used' => $used,
            'daily_limit' => $limit,
        ]);

        throw new AssistantBudgetExceededException(
            __('You have reached today\'s assistant usage limit. Please try again tomorrow.')
        );
    }

    public function record(int $accountId, int $inputTokens, int $outputTokens): void
    {
        if ($this->dailyTokenLimit() <= 0) {
            return;
        }

        $tokens = max($inputTokens + $outputTokens, 0);

        if ($tokens === 0) {
            return;
        }

        $key = $this->key($accountId);

        // add() seeds the counter with the window's TTL; increment() keeps it.
        if (!$this->cache->add($key, $tokens, $this->secondsUntilTomorrow())) {
            $this->cache->increment($key, $tokens);
        }
    }

    public function usedToday(int $accountId): int
    {
        return (int)$this->cache->get($this->key($accountId), 0);
    }

    private function dailyTokenLimit(): int
    {
        return (int)$this->config->get('assistant.daily_token_limit', 0);
    }

    private function key(int $accountId): string
    {
        return sprintf('assistant.usage.%d.%s', $accountId, Carbon::now('UTC')->toDateString());
    }

    private function secondsUntilTomorrow(): int
    {
        $now = Carbon::now('UTC');

        return max((int)$now->copy()->addDay()->startOfDay()->diffInSeconds($now, absolute: true), 60);
    }
}
