<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SeatDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<SeatDomainObject>
 */
interface SeatRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, SeatDomainObject> seats with a derived state (AVAILABLE|HELD|SOLD),
     * ordered deterministically by section, row and seat number.
     */
    public function findByEventIdWithState(int $eventId, ?array $sectionIds = null): Collection;

    /**
     * Atomically claims the given seats for an order. A seat is claimable when it has no
     * attendee and either has no order or its order is deleted, cancelled, abandoned or has
     * an expired reservation. Returns the number of seats actually claimed.
     */
    public function claimSeats(int $orderId, int $eventId, array $seatIds, array $sectionIds): int;

    public function findByOrderId(int $orderId): Collection;

    /**
     * Rewrites the denormalized attendees.seat_label for every sold seat of a section,
     * using the given (possibly renamed) section name.
     */
    public function updateAttendeeSeatLabelsForSection(int $sectionId, string $sectionName): int;

    /**
     * @return array<int, array<string, int>> seat counts per section id, keyed by state name
     */
    public function getSeatCountsBySection(int $eventId): array;
}
