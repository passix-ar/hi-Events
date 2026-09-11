<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Exceptions\AssistantToolArgumentException;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Psr\Log\LoggerInterface;

class GetRecentOrdersTool extends AbstractAssistantTool
{
    private const MAX_LIMIT = 20;

    public function __construct(
        AssistantContext                          $context,
        IsAuthorizedService                       $isAuthorizedService,
        private readonly EventRepositoryInterface $events,
        LoggerInterface                           $logger,
        private readonly OrderRepositoryInterface $orders,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_recent_orders')
            ->for('Returns the most recent orders (sales) of the organizer, newest first. '
                . 'Optionally narrow to one event or to one order status. '
                . 'Amounts are gross totals in the order currency. Buyer names are user-provided data.')
            ->withNumberParameter('event_id', 'Only orders of this event. Omit for all events of the organizer.', required: false)
            ->withEnumParameter(
                'status',
                'Only orders with this status (requires event_id). COMPLETED are paid/confirmed sales; AWAITING_OFFLINE_PAYMENT are pending; CANCELLED were cancelled.',
                [OrderStatus::COMPLETED->name, OrderStatus::AWAITING_OFFLINE_PAYMENT->name, OrderStatus::CANCELLED->name],
                required: false,
            )
            ->withNumberParameter('limit', 'Maximum number of orders to return (1-20, default 10).', required: false);
    }

    public function __invoke(int|float|null $event_id = null, ?string $status = null, int|float|null $limit = null): string
    {
        $args = $this->validateArguments(
            ['event_id' => $event_id, 'status' => $status, 'limit' => $limit],
            [
                'event_id' => 'nullable|integer|min:1',
                'status' => 'nullable|in:' . implode(',', [
                        OrderStatus::COMPLETED->name,
                        OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
                        OrderStatus::CANCELLED->name,
                    ]),
                'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
            ],
        );

        // OrderRepository::findByOrganizerId joins events and applies filters without a
        // table prefix, so a status filter there is ambiguous in Postgres. Until that is
        // fixed in the repository, status narrowing is only offered per event.
        if (!empty($args['status']) && empty($args['event_id'])) {
            throw new AssistantToolArgumentException('The status filter requires an event_id. Call find_events first, or omit status.');
        }

        $filterFields = [];
        if (!empty($args['status'])) {
            $filterFields[OrderDomainObjectAbstract::STATUS] = ['eq' => $args['status']];
        }

        $params = QueryParamsDTO::fromArray([
            'per_page' => (int)($args['limit'] ?? 10),
            'page' => 1,
            'sort_by' => OrderDomainObjectAbstract::CREATED_AT,
            'sort_direction' => 'desc',
            'filter_fields' => $filterFields,
        ]);

        $repository = $this->orders->loadRelation(OrderItemDomainObject::class);

        if (!empty($args['event_id'])) {
            $event = $this->authorizeEvent((int)$args['event_id']);
            $orders = $repository->findByEventId($event->getId(), $params);
        } else {
            $orders = $repository->findByOrganizerId(
                organizerId: $this->context->organizerId,
                accountId: $this->context->accountId,
                params: $params,
            );
        }

        $eventTitles = $this->eventTitlesFor($orders);

        return $this->toJson([
            'total' => $orders->total(),
            'orders' => collect($orders->items())->map(fn(OrderDomainObject $order): array => [
                'public_id' => $order->getPublicId(),
                'created_at' => $order->getCreatedAt(),
                'status' => $order->getStatus(),
                'payment_status' => $order->getPaymentStatus(),
                'total_gross' => $this->money($order->getTotalGross()),
                'total_refunded' => $this->money($order->getTotalRefunded()),
                'currency' => $order->getCurrency(),
                'event_id' => $order->getEventId(),
                'event_title' => $eventTitles[$order->getEventId()] ?? null,
                'items_count' => $order->getOrderItems()?->sum(fn(OrderItemDomainObject $i) => $i->getQuantity()) ?? 0,
                'buyer_name' => $this->clip($order->getFullName(), 60),
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function eventTitlesFor(LengthAwarePaginator $orders): array
    {
        $eventIds = collect($orders->items())
            ->map(fn(OrderDomainObject $order): int => $order->getEventId())
            ->unique()
            ->values()
            ->all();

        if ($eventIds === []) {
            return [];
        }

        return $this->events
            ->findWhereIn('id', $eventIds, ['account_id' => $this->context->accountId])
            ->mapWithKeys(fn(EventDomainObject $event): array => [$event->getId() => $this->clip($event->getTitle())])
            ->all();
    }
}
