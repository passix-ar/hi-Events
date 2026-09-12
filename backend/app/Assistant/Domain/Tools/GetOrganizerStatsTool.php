<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\OrganizerReportTypes;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\DTO\GetOrganizerStatsRequestDTO;
use HiEvents\Services\Application\Handlers\Organizer\GetOrganizerStatsHandler;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetOrganizerReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetOrganizerReportHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class GetOrganizerStatsTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext                            $context,
        IsAuthorizedService                         $isAuthorizedService,
        EventRepositoryInterface                    $events,
        LoggerInterface                             $logger,
        private readonly GetOrganizerStatsHandler   $organizerStats,
        private readonly GetOrganizerReportHandler  $organizerReports,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_organizer_stats')
            ->for('Sales overview for the whole organizer (all events combined) in the organizer currency. '
                . 'Always returns all-time totals and a per-event performance table. '
                . 'If start_date/end_date are given (YYYY-MM-DD, max 370 days apart) it also returns the totals '
                . 'for that period, e.g. to answer "how much did I sell this month".')
            ->withStringParameter('start_date', 'Period start (YYYY-MM-DD) in the organizer timezone.', required: false)
            ->withStringParameter('end_date', 'Period end (YYYY-MM-DD) in the organizer timezone.', required: false);
    }

    public function __invoke(?string $start_date = null, ?string $end_date = null): string
    {
        $args = $this->validateArguments(
            ['start_date' => $start_date, 'end_date' => $end_date],
            ['start_date' => 'nullable|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d'],
        );

        [$start, $end] = $this->resolveDateRange($args['start_date'] ?? null, $args['end_date'] ?? null);

        $totals = $this->organizerStats->handle(new GetOrganizerStatsRequestDTO(
            organizerId: $this->context->organizerId,
            accountId: $this->context->accountId,
            currencyCode: $this->context->currency,
        ));

        $payload = [
            'currency' => $totals->currency_code,
            'all_time' => [
                'gross_sales' => $this->money($totals->total_gross_sales),
                'refunded' => $this->money($totals->total_refunded),
                'fees' => $this->money($totals->total_fees),
                'tax' => $this->money($totals->total_tax),
                'orders' => $totals->total_orders,
                'tickets_sold' => $totals->total_products_sold,
                'attendees' => $totals->total_attendees_registered,
                'page_views' => (int)$totals->total_views,
            ],
            'events' => $this->eventsPerformance(),
        ];

        if ($start !== null && $end !== null) {
            $payload['period'] = $this->periodTotals($start->toDateString(), $end->toDateString());
        }

        return $this->toJson($payload);
    }

    private function periodTotals(string $startDate, string $endDate): array
    {
        /** @var Collection $rows */
        $rows = $this->organizerReports->handle(new GetOrganizerReportDTO(
            organizerId: $this->context->organizerId,
            reportType: OrganizerReportTypes::REVENUE_SUMMARY,
            startDate: $startDate,
            endDate: $endDate,
            currency: $this->context->currency,
        ));

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'gross_sales' => $this->money($rows->sum(fn(object $r) => (float)$r->gross_sales)),
            'net_revenue' => $this->money($rows->sum(fn(object $r) => (float)$r->net_revenue)),
            'refunded' => $this->money($rows->sum(fn(object $r) => (float)$r->total_refunded)),
            'orders' => (int)$rows->sum(fn(object $r) => (int)$r->order_count),
            'days_with_sales' => $rows->filter(fn(object $r) => (int)$r->order_count > 0)->count(),
        ];
    }

    private function eventsPerformance(): array
    {
        /** @var Collection $rows */
        $rows = $this->organizerReports->handle(new GetOrganizerReportDTO(
            organizerId: $this->context->organizerId,
            reportType: OrganizerReportTypes::EVENTS_PERFORMANCE,
            startDate: null,
            endDate: null,
            currency: $this->context->currency,
        ));

        return $rows->map(fn(object $r): array => [
            'event_id' => (int)$r->event_id,
            'title' => $this->clip($r->event_name),
            'state' => $r->event_state,
            'start_date' => $r->start_date,
            'tickets_sold' => (int)$r->products_sold,
            'orders' => (int)$r->total_orders,
            'gross_revenue' => $this->money($r->gross_revenue),
            'net_revenue' => $this->money($r->net_revenue),
            'unique_customers' => (int)$r->unique_customers,
        ])->values()->all();
    }
}
