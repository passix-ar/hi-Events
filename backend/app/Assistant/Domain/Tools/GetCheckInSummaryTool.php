<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\OrganizerReportTypes;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetOrganizerReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetOrganizerReportHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class GetCheckInSummaryTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext                           $context,
        IsAuthorizedService                        $isAuthorizedService,
        EventRepositoryInterface                   $events,
        LoggerInterface                            $logger,
        private readonly GetOrganizerReportHandler $organizerReports,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_check_in_summary')
            ->for('Attendance per event for this organizer: attendees, how many were checked in, the check-in rate '
                . 'and how many check-in lists exist. Use it for "how many people came", "how is the door going" '
                . 'or to compare attendance across events. Pass event_id to look at one event only.')
            ->withNumberParameter('event_id', 'Only this event. Omit for all events of the organizer.', required: false);
    }

    public function __invoke(int|float|null $event_id = null): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'nullable|integer|min:1']);

        $eventId = null;
        if (!empty($args['event_id'])) {
            $eventId = $this->authorizeEvent((int)$args['event_id'])->getId();
        }

        /** @var Collection $rows */
        $rows = $this->organizerReports->handle(new GetOrganizerReportDTO(
            organizerId: $this->context->organizerId,
            reportType: OrganizerReportTypes::CHECK_IN_SUMMARY,
            startDate: null,
            endDate: null,
            currency: $this->context->currency,
        ));

        // The organizer report ignores eventId (only PlatformFeesReport reads it),
        // so the narrowing happens here, on rows that are already organizer-scoped.
        if ($eventId !== null) {
            $rows = $rows->filter(fn(object $r): bool => (int)$r->event_id === $eventId);
        }

        $events = $rows->map(fn(object $r): array => [
            'event_id' => (int)$r->event_id,
            'title' => $this->clip((string)$r->event_name),
            'attendees' => (int)$r->total_attendees,
            'checked_in' => (int)$r->total_checked_in,
            'check_in_rate_percent' => round((float)$r->check_in_rate, 1),
            'check_in_lists' => (int)$r->check_in_lists_count,
        ])->values()->all();

        return $this->toJson([
            'events' => $events,
            'totals' => [
                'attendees' => array_sum(array_column($events, 'attendees')),
                'checked_in' => array_sum(array_column($events, 'checked_in')),
            ],
        ]);
    }
}
