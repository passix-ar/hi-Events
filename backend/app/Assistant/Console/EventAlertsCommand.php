<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Console;

use HiEvents\Assistant\Domain\Alerts\EventAlertService;
use Illuminate\Console\Command;

/**
 * Scheduled daily: emails organizers whose upcoming LIVE events are selling
 * behind the configured threshold. --dry-run lists them without sending.
 */
class EventAlertsCommand extends Command
{
    protected $signature = 'assistant:event-alerts
                            {--dry-run : List the alerts without sending or marking them}';

    protected $description = 'Email organizers about upcoming LIVE events whose sales are behind';

    public function handle(EventAlertService $service): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $alerts = $service->scan($dryRun);

        if ($alerts === []) {
            $this->info(($dryRun ? '[dry run] ' : '') . 'No events behind on sales.');

            return self::SUCCESS;
        }

        $this->table(
            ['Event', 'Sold / capacity', '%', 'Days left', 'Benchmark', 'Sent'],
            array_map(static fn(array $a): array => [
                sprintf('#%d %s', $a['event_id'], $a['title']),
                $a['sold'] . ' / ' . $a['capacity'],
                $a['sold_pct'] . '%',
                $a['days_left'],
                $a['benchmark_pct'] === null ? '-' : $a['benchmark_pct'] . '%',
                $dryRun ? 'dry run' : ($a['sent'] ? 'yes' : 'no'),
            ], $alerts),
        );

        $this->info(sprintf(
            '%s%d event(s) behind, %d email(s) sent.',
            $dryRun ? '[dry run] ' : '',
            count($alerts),
            count(array_filter($alerts, static fn(array $a): bool => $a['sent'])),
        ));

        return self::SUCCESS;
    }
}
