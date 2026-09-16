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

class GetPromoCodesPerformanceTool extends AbstractAssistantTool
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
            ->as('get_promo_codes_performance')
            ->for('How each promo code of one event performed: times used, unique buyers, gross sales it brought, '
                . 'total discount given, remaining uses and whether it is still active. Covers the whole sales '
                . 'history by default; pass start_date/end_date (YYYY-MM-DD, max 370 days apart) for a period.')
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

        if ($start === null && $end === null) {
            $start = Carbon::parse($event->getCreatedAt(), $this->context->timezone)->startOfDay();
            $end = Carbon::now($this->context->timezone)->endOfDay();
        }

        /** @var Collection $rows */
        $rows = $this->reports->handle(new GetReportDTO(
            eventId: $event->getId(),
            reportType: ReportTypes::PROMO_CODES_REPORT,
            startDate: $start?->toDateString(),
            endDate: $end?->toDateString(),
        ));

        $codes = $rows
            ->map(fn(object $r): array => [
                'code' => $this->clip((string)$r->promo_code, 40),
                'times_used' => (int)$r->times_used,
                'unique_customers' => (int)$r->unique_customers,
                'gross_sales' => $this->money($r->total_gross_sales),
                'discount_given' => $this->money($r->total_discount_amount),
                'remaining_uses' => $r->remaining_uses === null ? 'unlimited' : (int)$r->remaining_uses,
                'status' => $r->status,
                'last_used_at' => $r->last_used_at,
            ])
            ->sortByDesc('times_used')
            ->values()
            ->all();

        return $this->toJson([
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'currency' => $event->getCurrency(),
            'start_date' => $start?->toDateString(),
            'end_date' => $end?->toDateString(),
            'codes' => $codes,
        ]);
    }
}
