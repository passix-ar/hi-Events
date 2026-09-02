<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Models\Seat;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<SeatDomainObject>
 */
class SeatRepository extends BaseRepository implements SeatRepositoryInterface
{
    protected function getModel(): string
    {
        return Seat::class;
    }

    public function getDomainObject(): string
    {
        return SeatDomainObject::class;
    }

    public function findByEventIdWithState(int $eventId, ?array $sectionIds = null): Collection
    {
        $sectionFilter = '';
        if ($sectionIds !== null) {
            if (empty($sectionIds)) {
                return collect();
            }
            $sectionFilter = 'AND seats.seating_section_id IN ('.implode(',', array_fill(0, count($sectionIds), '?')).')';
        }

        $results = $this->db->select(<<<SQL
            SELECT
                seats.id,
                seats.event_id,
                seats.seating_section_id,
                seats.row_label,
                seats.seat_number,
                seats.label,
                CASE
                    WHEN seats.is_disabled THEN 'DISABLED'
                    WHEN orders.id IS NOT NULL
                         AND orders.deleted_at IS NULL
                         AND orders.status IN (?, ?) THEN 'SOLD'
                    WHEN orders.id IS NOT NULL
                         AND orders.deleted_at IS NULL
                         AND orders.status = ?
                         AND (orders.reserved_until IS NULL OR orders.reserved_until > NOW()) THEN 'HELD'
                    WHEN seats.order_id IS NULL AND seats.attendee_id IS NOT NULL THEN 'SOLD'
                    ELSE 'AVAILABLE'
                END AS state
            FROM seats
            LEFT JOIN orders ON orders.id = seats.order_id
            WHERE seats.event_id = ?
            $sectionFilter
            ORDER BY seats.seating_section_id, LENGTH(seats.row_label), seats.row_label, seats.seat_number
        SQL, [
            OrderStatus::COMPLETED->name,
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            OrderStatus::RESERVED->name,
            $eventId,
            ...($sectionIds !== null ? array_values($sectionIds) : []),
        ]);

        return collect($results)->map(static fn ($row) => SeatDomainObject::hydrateFromArray((array) $row));
    }

    public function claimSeats(int $orderId, int $eventId, array $seatIds, array $sectionIds): int
    {
        if (empty($seatIds) || empty($sectionIds)) {
            return 0;
        }

        $seatPlaceholders = implode(',', array_fill(0, count($seatIds), '?'));
        $sectionPlaceholders = implode(',', array_fill(0, count($sectionIds), '?'));

        return $this->db->update(<<<SQL
            UPDATE seats
            SET order_id = ?, attendee_id = NULL, updated_at = NOW()
            WHERE seats.id IN ($seatPlaceholders)
              AND seats.event_id = ?
              AND seats.seating_section_id IN ($sectionPlaceholders)
              AND seats.is_disabled = FALSE
              AND (
                  (seats.order_id IS NULL AND seats.attendee_id IS NULL)
                  OR EXISTS (
                      SELECT 1 FROM orders
                      WHERE orders.id = seats.order_id
                        AND (
                            orders.deleted_at IS NOT NULL
                            OR orders.status IN (?, ?)
                            OR (orders.status = ? AND orders.reserved_until <= NOW())
                        )
                  )
              )
        SQL, [
            $orderId,
            ...array_values($seatIds),
            $eventId,
            ...array_values($sectionIds),
            OrderStatus::CANCELLED->name,
            OrderStatus::ABANDONED->name,
            OrderStatus::RESERVED->name,
        ]);
    }

    public function updateAttendeeSeatLabelsForSection(int $sectionId, string $sectionName): int
    {
        return $this->db->update(<<<'SQL'
            UPDATE attendees
            SET seat_label = ? || ' - ' || seats.label
            FROM seats
            WHERE seats.attendee_id = attendees.id
              AND seats.seating_section_id = ?
        SQL, [
            $sectionName,
            $sectionId,
        ]);
    }

    public function getSeatCountsBySection(int $eventId): array
    {
        $results = $this->db->select(<<<'SQL'
            SELECT
                seats.seating_section_id,
                CASE
                    WHEN seats.is_disabled THEN 'DISABLED'
                    WHEN orders.id IS NOT NULL
                         AND orders.deleted_at IS NULL
                         AND orders.status IN (?, ?) THEN 'SOLD'
                    WHEN orders.id IS NOT NULL
                         AND orders.deleted_at IS NULL
                         AND orders.status = ?
                         AND (orders.reserved_until IS NULL OR orders.reserved_until > NOW()) THEN 'HELD'
                    WHEN seats.order_id IS NULL AND seats.attendee_id IS NOT NULL THEN 'SOLD'
                    ELSE 'AVAILABLE'
                END AS state,
                COUNT(*) AS seat_count
            FROM seats
            LEFT JOIN orders ON orders.id = seats.order_id
            WHERE seats.event_id = ?
            GROUP BY seats.seating_section_id, state
        SQL, [
            OrderStatus::COMPLETED->name,
            OrderStatus::AWAITING_OFFLINE_PAYMENT->name,
            OrderStatus::RESERVED->name,
            $eventId,
        ]);

        $counts = [];
        foreach ($results as $row) {
            $counts[$row->seating_section_id][$row->state] = (int) $row->seat_count;
        }

        return $counts;
    }

    public function findByOrderId(int $orderId): Collection
    {
        $this->model = $this->model->orderByRaw('seating_section_id, LENGTH(row_label), row_label, seat_number');

        return $this->findWhere([
            SeatDomainObject::ORDER_ID => $orderId,
        ]);
    }
}
