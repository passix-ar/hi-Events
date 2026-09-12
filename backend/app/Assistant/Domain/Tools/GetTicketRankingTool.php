<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\ReportTypes;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetReportHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class GetTicketRankingTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext                  $context,
        IsAuthorizedService               $isAuthorizedService,
        EventRepositoryInterface          $events,
        LoggerInterface                   $logger,
        private readonly GetReportHandler $reports,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_ticket_ranking')
            ->for('Ticket types of one event ranked by units sold (completed orders only), with gross revenue per type. '
                . 'Covers the whole sales history of the event by default, so call it once with just the event_id. '
                . 'Only pass start_date/end_date (YYYY-MM-DD, max 370 days apart) when the user asks about a specific period.')
            ->withNumberParameter('event_id', 'The event id.')
            ->withStringParameter('start_date', 'Period start (YYYY-MM-DD).', required: false)
            ->withStringParameter('end_date', 'Period end (YYYY-MM-DD).', required: false);
    }

    public function __invoke(int|float $event_id, ?string $start_date = null, ?string $end_date = null): string
    {
        $args = $this->validateArguments(
            ['event_id' => $event_id, 'start_date' => $start_date, 'end_date' => $end_date],
            [
                'event_id' => 'required|integer|min:1',
                'start_date' => 'nullable|date_format:Y-m-d',
                'end_date' => 'nullable|date_format:Y-m-d',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        [$start, $end] = $this->resolveDateRange($args['start_date'] ?? null, $args['end_date'] ?? null);

        // No period asked for: cover the event's whole sales history instead of the
        // report service's 30-day default, so "which ticket sold most" needs one call.
        if ($start === null && $end === null) {
            $start = Carbon::parse($event->getCreatedAt(), $this->context->timezone)->startOfDay();
            $end = Carbon::now($this->context->timezone)->endOfDay();
        }

        /** @var Collection $rows */
        $rows = $this->reports->handle(new GetReportDTO(
            eventId: $event->getId(),
            reportType: ReportTypes::PRODUCT_SALES,
            startDate: $start?->toDateString(),
            endDate: $end?->toDateString(),
        ));

        $ranking = $rows
            ->map(fn(object $r): array => [
                'product_id' => (int)$r->product_id,
                'title' => $this->clip($r->product_title),
                'type' => $r->product_type,
                'sold' => (int)$r->number_sold,
                'gross_revenue' => $this->money($r->total_gross),
            ])
            ->sortByDesc('sold')
            ->values()
            ->all();

        return $this->toJson([
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'currency' => $event->getCurrency(),
            'start_date' => $start?->toDateString(),
            'end_date' => $end?->toDateString(),
            'total_sold' => array_sum(array_column($ranking, 'sold')),
            'ranking' => $ranking,
        ]);
    }
}
