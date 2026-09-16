<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Door assistant: "did Juan come?", "is María checked in?".
 *
 * Returns the minimum an organizer needs to confirm identity at the door:
 * name, ticket type, status, check-in state and a masked email. Never notes,
 * question answers, phone or address.
 */
class FindAttendeeTool extends AbstractAssistantTool
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 10;

    public function __construct(
        AssistantContext                            $context,
        IsAuthorizedService                         $isAuthorizedService,
        EventRepositoryInterface                    $events,
        LoggerInterface                             $logger,
        private readonly AttendeeRepositoryInterface $attendees,
        private readonly OrderRepositoryInterface    $orders,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('find_attendee')
            ->for('Look up attendees of one event on the day of the event: "did X come?", "is Juan checked in?", '
                . '"which ticket does María have?". Searches by part of a name, an email, an attendee public id '
                . 'or an order public id. Returns name, ticket type, status, whether they checked in and a masked '
                . 'email (only the first two characters are visible, on purpose). Use get_door_status for totals.')
            ->withNumberParameter('event_id', 'The event id.')
            ->withStringParameter('query', 'Part of a name, an email, or an attendee/order public id (2 to 80 characters).')
            ->withNumberParameter('limit', 'Maximum matches to return, 1 to 10 (default 5).', required: false);
    }

    public function __invoke(int|float $event_id, string $query, int|float|null $limit = null): string
    {
        $args = $this->validateArguments(
            ['event_id' => $event_id, 'query' => trim($query), 'limit' => $limit],
            [
                'event_id' => 'required|integer|min:1',
                'query' => 'required|string|min:2|max:80',
                'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
            ],
        );

        $eventId = $this->authorizeEvent((int)$args['event_id'])->getId();
        $limit = (int)($args['limit'] ?? self::DEFAULT_LIMIT);
        // '%' would turn the ILIKE into "match everything"; underscores are legit in emails
        // but also single-char wildcards, so the query must keep at least two real
        // characters once the metacharacters are gone.
        $search = trim(str_replace('%', '', $args['query']));

        if (preg_match_all('/[\p{L}\p{N}@.]/u', $search) < 2) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'Give at least two letters or digits of the name, email or id.']);
        }

        $matches = $this->searchAttendees($eventId, $search, $limit);
        $matchedBy = 'attendee';

        if ($matches->isEmpty()) {
            $matches = $this->searchByOrderPublicId($eventId, $search, $limit);
            $matchedBy = $matches->isEmpty() ? 'none' : 'order_public_id';
        }

        return $this->toJson([
            'event_id' => $eventId,
            'query' => $this->clip($search, 80),
            'matched_by' => $matchedBy,
            'count' => $matches->count(),
            'truncated' => $matches->count() >= $limit,
            'attendees' => $matches
                ->map(fn(AttendeeDomainObject $a): array => $this->present($a))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return Collection<AttendeeDomainObject>
     */
    private function searchAttendees(int $eventId, string $search, int $limit): Collection
    {
        $page = $this->withDoorRelations()->findByEventId($eventId, new QueryParamsDTO(
            page: 1,
            per_page: $limit,
            sort_by: AttendeeDomainObject::LAST_NAME,
            sort_direction: 'asc',
            query: $search,
        ));

        return collect($page->items());
    }

    /**
     * The attendee search matches names, emails and attendee public ids, but not the
     * order public id printed on the receipt. Exact match only, so a short string
     * cannot pull every attendee of the event.
     *
     * @return Collection<AttendeeDomainObject>
     */
    private function searchByOrderPublicId(int $eventId, string $search, int $limit): Collection
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orders->findFirstWhere([
            OrderDomainObject::EVENT_ID => $eventId,
            OrderDomainObject::PUBLIC_ID => $search,
        ]) ?? $this->orders->findFirstWhere([
            OrderDomainObject::EVENT_ID => $eventId,
            OrderDomainObject::PUBLIC_ID => strtoupper($search),
        ]);

        if ($order === null) {
            return collect();
        }

        return $this->withDoorRelations()
            ->findWhere([
                AttendeeDomainObject::EVENT_ID => $eventId,
                AttendeeDomainObject::ORDER_ID => $order->getId(),
            ])
            ->take($limit);
    }

    private function withDoorRelations(): AttendeeRepositoryInterface
    {
        return $this->attendees
            ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'))
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->loadRelation(new Relationship(AttendeeCheckInDomainObject::class, name: 'check_ins'));
    }

    private function present(AttendeeDomainObject $attendee): array
    {
        /** @var AttendeeCheckInDomainObject|null $latestCheckIn */
        $latestCheckIn = ($attendee->getCheckIns() ?? collect())
            ->sortByDesc(fn(AttendeeCheckInDomainObject $c): string => (string)$c->getCreatedAt())
            ->first();

        // Modern check-in lists write attendee_check_ins; the legacy endpoint stamps
        // attendees.checked_in_at. Either one means the person is inside.
        $checkedInAt = $latestCheckIn?->getCreatedAt() ?? $attendee->getCheckedInAt();

        return [
            'public_id' => $attendee->getPublicId(),
            'first_name' => $this->clip($attendee->getFirstName(), 40),
            'last_name' => $this->clip($attendee->getLastName(), 40),
            'email' => $this->maskEmail($attendee->getEmail()),
            'status' => $attendee->getStatus(),
            'ticket' => $this->clip($attendee->getProduct()?->getTitle(), 60),
            'checked_in' => $checkedInAt !== null,
            'checked_in_at' => $checkedInAt !== null ? $this->localTime($checkedInAt) : null,
            'check_in_list_id' => $latestCheckIn?->getCheckInListId(),
            'order_public_id' => $attendee->getOrder()?->getPublicId(),
            'order_status' => $attendee->getOrder()?->getStatus(),
        ];
    }

    /**
     * `juan.perez@gmail.com` → `ju***@gmail.com`. Enough for the organizer to confirm
     * "is it the Gmail one?" without the model ever holding the full address.
     */
    private function maskEmail(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 2) . '***@' . $domain;
    }

    private function localTime(string $timestamp): string
    {
        return Carbon::parse($timestamp)->setTimezone($this->context->timezone)->format('Y-m-d H:i');
    }
}
