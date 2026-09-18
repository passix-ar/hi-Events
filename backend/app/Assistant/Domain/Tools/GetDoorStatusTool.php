<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Event\EventStatsFetchService;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Door assistant: "how many came in?", "how is the door going?" for ONE event.
 *
 * Counts come from the attendee rows plus their attendee_check_ins (the scanner flow).
 * The legacy attendees.checked_in_at stamp is honoured too, so an attendee counts as
 * inside if either source says so.
 */
class GetDoorStatusTool extends AbstractAssistantTool
{
    private const RECENT_CHECK_INS = 10;

    public function __construct(
        AssistantContext                               $context,
        IsAuthorizedService                            $isAuthorizedService,
        EventRepositoryInterface                       $events,
        LoggerInterface                                $logger,
        private readonly AttendeeRepositoryInterface   $attendees,
        private readonly CheckInListRepositoryInterface $checkInLists,
        private readonly EventStatsFetchService        $eventStatsFetchService,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_door_status')
            ->for('Live door status of one event: attendees expected, how many already checked in, the same split '
                . 'per ticket type, the last check-ins (time, first name, ticket) and how many check-in lists exist. '
                . 'Use it on the day of the event for "how many came in?", "how is the door going?", '
                . '"how many VIPs entered?". Use find_attendee to look up one person.')
            ->withNumberParameter('event_id', 'The event id.');
    }

    public function __invoke(int|float $event_id): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);

        $event = $this->authorizeEvent((int)$args['event_id']);
        $eventId = $event->getId();

        // Export query: attendees of COMPLETED / AWAITING_OFFLINE_PAYMENT / CANCELLED orders,
        // with product and check-ins hydrated. Cancelled attendees are not expected at the door.
        /** @var Collection<AttendeeDomainObject> $expected */
        $expected = $this->attendees
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->loadRelation(new Relationship(AttendeeCheckInDomainObject::class, name: 'check_ins'))
            ->findByEventIdForExport($eventId)
            ->filter(fn(AttendeeDomainObject $a): bool => $a->getStatus() !== AttendeeStatus::CANCELLED->name)
            ->values();

        $checkedIn = $expected->filter(fn(AttendeeDomainObject $a): bool => $this->isInside($a));

        $perTicket = $expected
            ->groupBy(fn(AttendeeDomainObject $a): int => (int)$a->getProductId())
            ->map(function (Collection $group) {
                /** @var AttendeeDomainObject $first */
                $first = $group->first();
                $inside = $group->filter(fn(AttendeeDomainObject $a): bool => $this->isInside($a))->count();

                return [
                    'product_id' => (int)$first->getProductId(),
                    'ticket' => $this->clip($first->getProduct()?->getTitle(), 60),
                    'attendees' => $group->count(),
                    'checked_in' => $inside,
                    'pending' => $group->count() - $inside,
                ];
            })
            ->sortBy('ticket')
            ->values()
            ->all();

        $recent = $expected
            ->flatMap(fn(AttendeeDomainObject $a): Collection => ($a->getCheckIns() ?? collect())
                ->map(fn(AttendeeCheckInDomainObject $c): array => [
                    'time' => $this->localTime((string)$c->getCreatedAt()),
                    'sort' => (string)$c->getCreatedAt(),
                    'first_name' => $this->clip($a->getFirstName(), 40),
                    'ticket' => $this->clip($a->getProduct()?->getTitle(), 60),
                ]))
            ->sortByDesc('sort')
            ->take(self::RECENT_CHECK_INS)
            ->map(static fn(array $row): array => [
                'time' => $row['time'],
                'first_name' => $row['first_name'],
                'ticket' => $row['ticket'],
            ])
            ->values()
            ->all();

        // Kept for parity with get_event_stats: this counter only sees the legacy
        // attendees.checked_in_at stamp, so it can lag behind the scanner flow.
        $legacy = $this->eventStatsFetchService->getCheckedInStats($eventId);

        return $this->toJson([
            'event' => [
                'id' => $eventId,
                'title' => $this->clip($event->getTitle()),
                'status' => $event->getStatus(),
                'start_date' => $this->localDate($event->getStartDate(), $event->getTimezone()),
                'timezone' => $this->context->timezone,
            ],
            'totals' => [
                'attendees' => $expected->count(),
                'checked_in' => $checkedIn->count(),
                'pending' => $expected->count() - $checkedIn->count(),
                'check_in_rate_percent' => $expected->isEmpty()
                    ? 0.0
                    : round($checkedIn->count() / $expected->count() * 100, 1),
                'awaiting_payment' => $expected
                    ->filter(fn(AttendeeDomainObject $a): bool => $a->getStatus() === AttendeeStatus::AWAITING_PAYMENT->name)
                    ->count(),
            ],
            'per_ticket' => $perTicket,
            'recent_check_ins' => $recent,
            'check_in_lists' => $this->checkInLists->countWhere([CheckInListDomainObject::EVENT_ID => $eventId]),
            'legacy_counter' => [
                'checked_in' => (int)$legacy->total_checked_in_attendees,
                'total_attendees' => (int)$legacy->total_attendees,
                'note' => 'Only counts attendees.checked_in_at (legacy endpoint); totals above are the source of truth.',
            ],
            'notes' => [
                'Attendees with a CANCELLED status are excluded from every count.',
                'recent_check_ins only lists scanner check-ins (attendee_check_ins); legacy check-ins have no per-list record.',
            ],
        ]);
    }

    private function isInside(AttendeeDomainObject $attendee): bool
    {
        return ($attendee->getCheckIns()?->isNotEmpty() ?? false) || $attendee->getCheckedInAt() !== null;
    }

    private function localTime(string $timestamp): string
    {
        return Carbon::parse($timestamp)->setTimezone($this->context->timezone)->format('Y-m-d H:i');
    }
}
