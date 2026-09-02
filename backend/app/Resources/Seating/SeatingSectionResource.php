<?php

namespace HiEvents\Resources\Seating;

use HiEvents\DomainObjects\Enums\SeatState;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SeatingSectionDomainObject
 */
class SeatingSectionResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'event_id' => $this->getEventId(),
            'product_id' => $this->getProductId(),
            'row_count' => $this->getRowCount(),
            'seats_per_row' => $this->getSeatsPerRow(),
            'total_seats' => $this->getTotalSeatCount(),
            'status' => $this->getStatus(),
            'order' => $this->getOrder(),
            'aisle_positions' => $this->getAislePositions(),
            'layout_position' => $this->getLayoutPosition(),
            $this->mergeWhen(
                condition: $this->getSeatCounts() !== null,
                value: fn () => [
                    'seats_available' => $this->getSeatCounts()[SeatState::AVAILABLE->name] ?? 0,
                    'seats_held' => $this->getSeatCounts()[SeatState::HELD->name] ?? 0,
                    'seats_sold' => $this->getSeatCounts()[SeatState::SOLD->name] ?? 0,
                    'seats_disabled' => $this->getSeatCounts()[SeatState::DISABLED->name] ?? 0,
                ],
            ),
            $this->mergeWhen(
                condition: $this->getProduct() !== null,
                value: fn () => [
                    'product' => [
                        'id' => $this->getProduct()->getId(),
                        'title' => $this->getProduct()->getTitle(),
                    ],
                ],
            ),
            $this->mergeWhen(
                condition: $this->getSeats() !== null,
                value: fn () => [
                    'seats' => SeatResource::collection($this->getSeats()),
                ],
            ),
        ];
    }
}
