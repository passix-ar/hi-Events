<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Alerts;

use Carbon\CarbonImmutable;
use HiEvents\Assistant\Mail\EventSalesAlertMail;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventStatisticDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventStatisticRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Proactive sales alerts: LIVE events starting soon whose sell-through is
 * behind get one email a day to the organizer with a link to the panel.
 * Deterministic, no LLM involved.
 */
class EventAlertService
{
    private const BENCHMARK_EVENTS = 5;

    public function __construct(
        private readonly EventRepositoryInterface               $events,
        private readonly EventStatisticRepositoryInterface      $eventStatistics,
        private readonly OrganizerRepositoryInterface           $organizers,
        private readonly AccountRepositoryInterface             $accounts,
        private readonly AvailableProductQuantitiesFetchService $availableQuantities,
        private readonly LoggerInterface                        $logger,
    )
    {
    }

    /**
     * @return list<array{event_id:int,title:string,organizer_email:?string,sold:int,capacity:int,sold_pct:float,days_left:int,benchmark_pct:?float,sent:bool}>
     */
    public function scan(bool $dryRun = false): array
    {
        $now = CarbonImmutable::now('UTC');
        $daysAhead = (int)config('assistant.alerts.days_ahead', 14);
        $minSoldPct = (float)config('assistant.alerts.min_sold_pct', 40);

        $events = $this->events->findWhere([
            [EventDomainObjectAbstract::START_DATE, '>=', $now],
            [EventDomainObjectAbstract::START_DATE, '<=', $now->addDays($daysAhead)],
            EventDomainObjectAbstract::STATUS => EventStatus::LIVE->name,
        ]);

        $alerts = [];

        /** @var EventDomainObject $event */
        foreach ($events as $event) {
            $capacity = $this->capacityAndSold($event->getId());

            if ($capacity === null) {
                continue;
            }

            [$total, $sold] = $capacity;
            $soldPct = $total > 0 ? round($sold / $total * 100, 1) : 0.0;

            if ($soldPct >= $minSoldPct) {
                continue;
            }

            $startDate = CarbonImmutable::parse((string)$event->getStartDate(), 'UTC');
            $daysLeft = (int)$now->startOfDay()->diffInDays($startDate->startOfDay(), false);
            $benchmarkPct = $this->benchmarkPct($event);
            $email = $this->recipientEmail($event);

            $alert = [
                'event_id' => $event->getId(),
                'title' => (string)$event->getTitle(),
                'organizer_email' => $email,
                'sold' => $sold,
                'capacity' => $total,
                'sold_pct' => $soldPct,
                'days_left' => $daysLeft,
                'benchmark_pct' => $benchmarkPct,
                'sent' => false,
            ];

            if (!$dryRun && $email !== null) {
                $alert['sent'] = $this->send($email, $alert, $now);
            }

            $alerts[] = $alert;
        }

        return $alerts;
    }

    /**
     * @return array{0:int,1:int}|null [capacity, sold]; null when every price is uncapped
     */
    private function capacityAndSold(int $eventId): ?array
    {
        $prices = $this->availableQuantities
            ->getAvailableProductQuantities($eventId, ignoreCache: true)
            ->productQuantities
            ->filter(static fn(AvailableProductQuantitiesDTO $q): bool => $q->initial_quantity_available !== null);

        if ($prices->isEmpty()) {
            return null;
        }

        $capacity = (int)$prices->sum(static fn(AvailableProductQuantitiesDTO $q): int => (int)$q->initial_quantity_available);
        $available = (int)$prices->sum(static fn(AvailableProductQuantitiesDTO $q): int => min((int)$q->quantity_available, (int)$q->initial_quantity_available));

        return [$capacity, max(0, $capacity - $available)];
    }

    /**
     * Average final sell-through of the organizer's last past events, or null when there is none.
     */
    private function benchmarkPct(EventDomainObject $event): ?float
    {
        $pastEvents = $this->events->findWhere([
            [EventDomainObjectAbstract::ORGANIZER_ID, '=', $event->getOrganizerId()],
            [EventDomainObjectAbstract::ID, '!=', $event->getId()],
            [EventDomainObjectAbstract::START_DATE, '<', CarbonImmutable::now('UTC')],
        ])
            ->sortByDesc(static fn(EventDomainObject $e): string => (string)$e->getStartDate())
            ->take(self::BENCHMARK_EVENTS);

        $ratios = [];

        /** @var EventDomainObject $past */
        foreach ($pastEvents as $past) {
            $capacity = $this->capacityAndSold($past->getId());

            if ($capacity === null || $capacity[0] <= 0) {
                continue;
            }

            /** @var EventStatisticDomainObject|null $stats */
            $stats = $this->eventStatistics->findFirstWhere(['event_id' => $past->getId()]);

            if ($stats === null) {
                continue;
            }

            $ratios[] = min(100.0, $stats->getProductsSold() / $capacity[0] * 100);
        }

        return $ratios === [] ? null : round(array_sum($ratios) / count($ratios), 1);
    }

    private function recipientEmail(EventDomainObject $event): ?string
    {
        try {
            $email = $this->organizers->findById($event->getOrganizerId())->getEmail();

            if (trim($email) !== '') {
                return $email;
            }
        } catch (Throwable) {
            // fall through to the account email
        }

        try {
            $email = $this->accounts->findById($event->getAccountId())->getEmail();

            return trim($email) !== '' ? $email : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function send(string $email, array $alert, CarbonImmutable $now): bool
    {
        $key = sprintf('assistant.alert.%d.%s', $alert['event_id'], $now->format('Y-m-d'));

        if (!Cache::add($key, 1, 86400)) {
            return false;
        }

        // BaseMail implements ShouldQueue, so this is dispatched to the queue the worker drains.
        Mail::to($email)->send(new EventSalesAlertMail(
            eventId: $alert['event_id'],
            title: $alert['title'],
            sold: $alert['sold'],
            capacity: $alert['capacity'],
            soldPct: $alert['sold_pct'],
            daysLeft: $alert['days_left'],
            benchmarkPct: $alert['benchmark_pct'],
        ));

        $this->logger->info('assistant.event_alert.sent', [
            'event_id' => $alert['event_id'],
            'sold_pct' => $alert['sold_pct'],
            'days_left' => $alert['days_left'],
        ]);

        return true;
    }
}
