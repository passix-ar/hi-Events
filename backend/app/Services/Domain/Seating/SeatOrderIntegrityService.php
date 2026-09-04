<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;

class SeatOrderIntegrityService
{
    public function __construct(
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {}

    /**
     * Labels of the seats an order was given at completion but no longer holds, because the
     * reservation expired and another buyer claimed them. An order with no seats returns [].
     *
     * @return array<int, string>
     */
    public function findSeatsLostByOrder(int $orderId): array
    {
        $seatedAttendees = $this->attendeeRepository
            ->findWhere([AttendeeDomainObjectAbstract::ORDER_ID => $orderId])
            ->filter(static fn (AttendeeDomainObject $attendee) => $attendee->getSeatLabel() !== null);

        if ($seatedAttendees->isEmpty()) {
            return [];
        }

        $stillHeld = $this->seatRepository
            ->findByOrderId($orderId)
            ->map(static fn (SeatDomainObject $seat) => $seat->getAttendeeId())
            ->filter()
            ->all();

        return $seatedAttendees
            ->reject(static fn (AttendeeDomainObject $attendee) => in_array($attendee->getId(), $stillHeld, true))
            ->map(static fn (AttendeeDomainObject $attendee) => $attendee->getSeatLabel())
            ->values()
            ->all();
    }
}
