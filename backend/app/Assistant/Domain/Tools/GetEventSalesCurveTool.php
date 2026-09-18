<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\ReportTypes;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetReportHandler;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * The organizer's own sales history for one event: how fast it sold, at what
 * prices, and when the bulk of the sales came. This is what lets the assistant
 * answer "how much should the early bird cost" or "when do I open the general
 * tier" with the organizer's numbers instead of generic advice.
 */
class GetEventSalesCurveTool extends AbstractAssistantTool
{
    /** Above this many active days the curve is aggregated by week to stay compact. */
    public const MAX_DAILY_ROWS = 120;

    private const LEAD_WINDOW_DAYS = 7;

    public function __construct(
        AssistantContext                                        $context,
        IsAuthorizedService                                     $isAuthorizedService,
        EventRepositoryInterface                                $events,
        LoggerInterface                                         $logger,
        private readonly GetReportHandler                       $reports,
        private readonly ProductRepositoryInterface             $products,
        private readonly AvailableProductQuantitiesFetchService $availableQuantities,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_event_sales_curve')
            ->for('Sales history of one of the organizer\'s events, usually a PAST one, to ground pricing and timing '
                . 'advice in their own numbers: day-by-day orders/tickets/gross from the event\'s creation to its start '
                . '(weekly if the range is long), each ticket type with its price, initial quantity and units sold, '
                . 'and a summary (days on sale, share of sales in the first 7 days on sale and in the last 7 days '
                . 'before the event, average tickets per order). Call it when the organizer asks what price or '
                . 'quantity to set, when to open a tier, or how a previous event went. Use find_events with '
                . 'period=past to pick comparable events first.')
            ->withNumberParameter('event_id', 'The event id.');
    }

    public function __invoke(int|float $event_id): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);

        $event = $this->authorizeEvent((int)$args['event_id']);
        $eventId = $event->getId();
        $timezone = $event->getTimezone() ?: $this->context->timezone;

        $today = Carbon::now($timezone)->endOfDay();
        $eventStart = $event->getStartDate()
            ? Carbon::parse($event->getStartDate())->setTimezone($timezone)->endOfDay()
            : null;
        $rangeStart = Carbon::parse($event->getCreatedAt())->setTimezone($timezone)->startOfDay();
        $rangeEnd = ($eventStart !== null && $eventStart->lessThan($today)) ? $eventStart->copy() : $today->copy();

        if ($rangeStart->greaterThan($rangeEnd)) {
            $rangeStart = $rangeEnd->copy()->startOfDay();
        }

        /** @var Collection<object> $rows */
        $rows = $this->reports->handle(new GetReportDTO(
            eventId: $eventId,
            reportType: ReportTypes::DAILY_SALES_REPORT,
            startDate: $rangeStart->toDateString(),
            endDate: $rangeEnd->toDateString(),
        ));

        $daily = $rows
            ->map(static fn(object $r): array => [
                'date' => substr((string)$r->date, 0, 10),
                'orders' => (int)$r->orders_created,
                'tickets' => (int)$r->products_sold,
                'gross' => (float)$r->sales_total_gross,
            ])
            ->filter(static fn(array $d): bool => $d['orders'] > 0 || $d['tickets'] > 0 || $d['gross'] > 0)
            ->sortBy('date')
            ->values();

        $summary = $this->buildSummary($daily, $eventStart, $rangeEnd, $timezone);
        [$curve, $granularity] = $this->compactCurve($daily, $timezone);

        return $this->toJson([
            'event' => [
                'id' => $eventId,
                'title' => $this->clip($event->getTitle()),
                'status' => $event->getStatus(),
                'start_date' => $this->localDate($event->getStartDate(), $timezone),
                'currency' => $event->getCurrency(),
                'timezone' => $timezone,
            ],
            'range' => [
                'from' => $rangeStart->toDateString(),
                'to' => $rangeEnd->toDateString(),
                'ends_at_event_start' => $eventStart !== null && $rangeEnd->isSameDay($eventStart),
            ],
            'summary' => $summary,
            'ticket_types' => $this->ticketTypes($eventId, $timezone),
            // Only the daily totals exist per day; there is no per-ticket-type daily breakdown.
            'per_ticket_timing_unavailable' => true,
            'curve_granularity' => $granularity,
            'curve_note' => $granularity === 'week'
                ? sprintf('More than %d active days: rows are ISO weeks (sums), keyed by the Monday.', self::MAX_DAILY_ROWS)
                : 'Days without any sale are omitted.',
            'curve' => $curve,
        ]);
    }

    private function buildSummary(Collection $daily, ?Carbon $eventStart, Carbon $rangeEnd, string $timezone): array
    {
        $ticketsTotal = (int)$daily->sum('tickets');
        $ordersTotal = (int)$daily->sum('orders');
        $grossTotal = $this->money($daily->sum('gross'));

        $firstSale = $daily->first(static fn(array $d): bool => $d['tickets'] > 0 || $d['orders'] > 0);
        $firstSaleDate = $firstSale !== null ? Carbon::parse($firstSale['date'], $timezone)->startOfDay() : null;
        $lastSale = $daily->last(static fn(array $d): bool => $d['tickets'] > 0 || $d['orders'] > 0);

        $daysOnSale = ($firstSaleDate !== null && $eventStart !== null)
            ? max(0, (int)$firstSaleDate->diffInDays($eventStart->copy()->startOfDay(), absolute: false))
            : null;

        $share = static function (int $part) use ($ticketsTotal): ?float {
            return $ticketsTotal > 0 ? round($part * 100 / $ticketsTotal, 1) : null;
        };

        $firstWindow = null;
        if ($firstSaleDate !== null) {
            $firstWindowEnd = $firstSaleDate->copy()->addDays(self::LEAD_WINDOW_DAYS - 1)->toDateString();
            $firstWindow = (int)$daily
                ->filter(static fn(array $d): bool => $d['date'] <= $firstWindowEnd)
                ->sum('tickets');
        }

        // The "last 7 days before the event" only mean something once the event has started.
        $eventStarted = $eventStart !== null && $eventStart->lessThanOrEqualTo($rangeEnd);
        $lastWindow = null;
        if ($eventStarted) {
            $lastWindowStart = $eventStart->copy()->startOfDay()->subDays(self::LEAD_WINDOW_DAYS - 1)->toDateString();
            $lastWindowEnd = $eventStart->toDateString();
            $lastWindow = (int)$daily
                ->filter(static fn(array $d): bool => $d['date'] >= $lastWindowStart && $d['date'] <= $lastWindowEnd)
                ->sum('tickets');
        }

        return [
            'first_sale_date' => $firstSaleDate?->toDateString(),
            'last_sale_date' => $lastSale['date'] ?? null,
            'days_on_sale' => $daysOnSale,
            'event_started' => $eventStarted,
            'orders_total' => $ordersTotal,
            'tickets_sold_total' => $ticketsTotal,
            'gross_total' => $grossTotal,
            'avg_tickets_per_order' => $ordersTotal > 0 ? round($ticketsTotal / $ordersTotal, 2) : null,
            'first_7_days_on_sale_share_pct' => $firstWindow !== null ? $share($firstWindow) : null,
            'last_7_days_before_event_share_pct' => $lastWindow !== null ? $share($lastWindow) : null,
        ];
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function compactCurve(Collection $daily, string $timezone): array
    {
        if ($daily->count() <= self::MAX_DAILY_ROWS) {
            $curve = $daily
                ->map(fn(array $d): array => [
                    'date' => $d['date'],
                    'orders' => $d['orders'],
                    'tickets' => $d['tickets'],
                    'gross' => $this->money($d['gross']),
                ])
                ->all();

            return [$curve, 'day'];
        }

        $weeks = $daily
            ->groupBy(static fn(array $d): string => Carbon::parse($d['date'], $timezone)->startOfWeek()->toDateString())
            ->map(fn(Collection $days, string $weekStart): array => [
                'week_of' => $weekStart,
                'orders' => (int)$days->sum('orders'),
                'tickets' => (int)$days->sum('tickets'),
                'gross' => $this->money($days->sum('gross')),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [$weeks, 'week'];
    }

    private function ticketTypes(int $eventId, string $timezone): array
    {
        $quantities = $this->availableQuantities
            ->getAvailableProductQuantities($eventId, ignoreCache: true)
            ->productQuantities
            ->keyBy(static fn(AvailableProductQuantitiesDTO $q): int => $q->price_id);

        /** @var Collection<ProductDomainObject> $products */
        $products = $this->products
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhere(['event_id' => $eventId]);

        $types = [];
        foreach ($products as $product) {
            /** @var ProductPriceDomainObject $price */
            foreach ($product->getProductPrices() ?? collect() as $price) {
                /** @var AvailableProductQuantitiesDTO|null $q */
                $q = $quantities->get($price->getId());
                $initial = $price->getInitialQuantityAvailable();
                $sold = $price->getQuantitySold();
                $available = $q?->quantity_available;
                $soldOut = $initial !== null && $available !== null && $available < Constants::INFINITE && $available <= 0;

                $types[] = [
                    'product_id' => $product->getId(),
                    'price_id' => $price->getId(),
                    'title' => $this->clip($product->getTitle()),
                    'price_label' => $this->clip($price->getLabel(), 40),
                    'product_type' => $product->getProductType(),
                    'pricing' => $product->getType(),
                    'price' => $this->money($price->getPrice()),
                    'initial_quantity' => $initial,
                    'sold' => $sold,
                    'available' => ($available === null || $available >= Constants::INFINITE) ? 'unlimited' : $available,
                    'sold_out' => $soldOut,
                    'sold_out_after_days' => null,
                    'sale_start_date' => $this->localDate($price->getSaleStartDate() ?? $product->getSaleStartDate(), $timezone),
                    'sale_end_date' => $this->localDate($price->getSaleEndDate() ?? $product->getSaleEndDate(), $timezone),
                ];
            }
        }

        usort($types, static fn(array $a, array $b): int => $b['sold'] <=> $a['sold']);

        return $types;
    }
}
