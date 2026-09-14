<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Constants;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\EventStatsRequestDTO;
use HiEvents\Services\Application\Handlers\Event\GetEventStatsHandler;
use HiEvents\Services\Domain\Event\EventStatsFetchService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class GetEventStatsTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext                                        $context,
        IsAuthorizedService                                     $isAuthorizedService,
        EventRepositoryInterface                                $events,
        LoggerInterface                                         $logger,
        private readonly GetEventStatsHandler                   $eventStats,
        private readonly EventStatsFetchService                 $eventStatsFetchService,
        private readonly AvailableProductQuantitiesFetchService $availableQuantities,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_event_stats')
            ->for('Full picture of one event: all-time sales totals, check-in progress and, per ticket type, '
                . 'how many are still available. Use find_events first if you only know the event name.')
            ->withNumberParameter('event_id', 'The event id.');
    }

    public function __invoke(int|float $event_id): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);

        $event = $this->authorizeEvent((int)$args['event_id']);
        $eventId = $event->getId();

        $stats = $this->eventStats->handle(EventStatsRequestDTO::fromArray([
            'event_id' => $eventId,
            'date_range_preset' => 'event',
        ]));

        $checkIn = $this->eventStatsFetchService->getCheckedInStats($eventId);
        $quantities = $this->availableQuantities->getAvailableProductQuantities($eventId, ignoreCache: true);

        return $this->toJson([
            'event' => [
                'id' => $eventId,
                'title' => $this->clip($event->getTitle()),
                'status' => $event->getStatus(),
                'start_date' => $event->getStartDate(),
                'end_date' => $event->getEndDate(),
                'currency' => $event->getCurrency(),
            ],
            'totals' => [
                'gross_sales' => $this->money($stats->total_gross_sales),
                'refunded' => $this->money($stats->total_refunded),
                'fees' => $this->money($stats->total_fees),
                'tax' => $this->money($stats->total_tax),
                'orders' => (int)$stats->total_orders,
                'tickets_sold' => (int)$stats->total_products_sold,
                'attendees' => (int)$stats->total_attendees_registered,
                'page_views' => (int)$stats->total_views,
            ],
            'check_in' => [
                'checked_in' => $checkIn->total_checked_in_attendees,
                'total_attendees' => $checkIn->total_attendees,
            ],
            'tickets' => $quantities->productQuantities
                ->map(fn(AvailableProductQuantitiesDTO $q): array => [
                    'product_id' => $q->product_id,
                    'title' => $this->clip($q->product_title),
                    'price_label' => $this->clip($q->price_label, 40),
                    'available' => $q->quantity_available >= Constants::INFINITE ? 'unlimited' : $q->quantity_available,
                    'reserved_in_checkout' => $q->quantity_reserved,
                    'initial_capacity' => $q->initial_quantity_available,
                ])->values()->all(),
        ]);
    }
}
