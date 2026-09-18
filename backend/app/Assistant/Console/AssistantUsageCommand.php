<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Console;

use HiEvents\Assistant\Domain\AssistantUsageLimiter;
use Illuminate\Console\Command;

/**
 * `php artisan assistant:usage [account_id...]` - today's and this month's
 * spend in token equivalents (and an estimate in US$ at Sonnet list price),
 * for the platform and for the given accounts.
 */
class AssistantUsageCommand extends Command
{
    protected $signature = 'assistant:usage {account?* : Account ids to show (platform totals are always shown)}';

    protected $description = 'Show assistant token spend today and this month, against the configured budgets';

    private const USD_PER_MILLION = 3.0;

    public function handle(AssistantUsageLimiter $limiter): int
    {
        $limits = $limiter->limits();
        $rows = [$this->row('platform', AssistantUsageLimiter::GLOBAL_ACCOUNT, $limiter, $limits['global_daily'], 0)];

        foreach ((array)$this->argument('account') as $accountId) {
            $rows[] = $this->row('account ' . $accountId, (int)$accountId, $limiter, $limits['daily'], $limits['monthly']);
        }

        $this->table(['Scope', 'Today', 'Daily limit', 'This month', 'Monthly limit', '≈ US$ today', '≈ US$ month'], $rows);
        $this->line('Token equivalents: cache reads x0.1, cache writes x1.25, output x5. US$ at Sonnet list price.');

        return self::SUCCESS;
    }

    private function row(string $label, int $accountId, AssistantUsageLimiter $limiter, int $dailyLimit, int $monthlyLimit): array
    {
        $today = $limiter->usedToday($accountId);
        $month = $limiter->usedThisMonth($accountId);

        return [
            $label,
            number_format($today),
            $dailyLimit > 0 ? number_format($dailyLimit) : '-',
            number_format($month),
            $monthlyLimit > 0 ? number_format($monthlyLimit) : '-',
            number_format($today / 1_000_000 * self::USD_PER_MILLION, 2),
            number_format($month / 1_000_000 * self::USD_PER_MILLION, 2),
        ];
    }
}
