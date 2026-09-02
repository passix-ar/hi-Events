<?php

namespace HiEvents\Resources\Seating;

use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SeatingSectionDomainObject
 */
class SeatingSectionResourcePublic extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'product_id' => $this->getProductId(),
            'row_count' => $this->getRowCount(),
            'seats_per_row' => $this->getSeatsPerRow(),
            'aisle_positions' => $this->getAislePositions(),
            'layout_position' => $this->getLayoutPosition(),
            $this->mergeWhen(
                condition: $this->getSeats() !== null,
                value: fn () => [
                    'seats' => SeatResourcePublic::collection($this->getSeats()),
                ],
            ),
        ];
    }
}
