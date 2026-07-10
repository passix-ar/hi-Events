<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;

class SeatAttendeeAssignmentService
{
    public function __construct(
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
    ) {}

    /**
     * Maps the order's claimed seats to its freshly created attendees. Seats and attendees of
     * each product are paired positionally: the Nth attendee of a product receives the Nth seat
     * (seats ordered by section, row and number; attendees by insertion order).
     *
     * Must be called inside the order-completion transaction, after attendees are created.
     *
     * @throws ResourceConflictException
     */
    public function assignSeatsForOrder(OrderDomainObject $order): void
    {
        $seats = $this->seatRepository->findByOrderId($order->getId());

        if ($seats->isEmpty()) {
            return;
        }

        $sectionsById = $this->seatingSectionRepository
            ->findWhereIn(
                field: SeatDomainObjectAbstract::ID,
                values: $seats->map(static fn (SeatDomainObject $seat) => $seat->getSeatingSectionId())->unique()->toArray(),
            )
            ->keyBy(static fn (SeatingSectionDomainObject $section) => $section->getId());

        $seatQueues = $seats
            ->groupBy(static fn (SeatDomainObject $seat) => $sectionsById->get($seat->getSeatingSectionId())->getProductId())
            ->map(static fn ($productSeats) => collect($productSeats->values()));

        $attendees = $this->attendeeRepository->findWhere(
            where: [AttendeeDomainObjectAbstract::ORDER_ID => $order->getId()],
            orderAndDirections: [new OrderAndDirection(AttendeeDomainObjectAbstract::ID)],
        );

        foreach ($attendees as $attendee) {
            /** @var AttendeeDomainObject $attendee */
            $queue = $seatQueues->get($attendee->getProductId());

            if ($queue === null || $queue->isEmpty()) {
                continue;
            }

            /** @var SeatDomainObject $seat */
            $seat = $queue->shift();
            $section = $sectionsById->get($seat->getSeatingSectionId());

            $this->attendeeRepository->updateFromArray($attendee->getId(), [
                AttendeeDomainObjectAbstract::SEAT_LABEL => $section->getName().' - '.$seat->getLabel(),
            ]);

            $this->seatRepository->updateFromArray($seat->getId(), [
                SeatDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
            ]);
        }

        $unassigned = $seatQueues->sum(static fn ($queue) => $queue->count());

        if ($unassigned > 0) {
            throw new ResourceConflictException(
                __('The number of selected seats does not match the number of attendees.')
            );
        }
    }
}
