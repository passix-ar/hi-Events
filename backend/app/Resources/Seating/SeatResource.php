<?php

namespace HiEvents\Resources\Seating;

use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin SeatDomainObject
 */
class SeatResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getId(),
            'seating_section_id' => $this->getSeatingSectionId(),
            'row_label' => $this->getRowLabel(),
            'seat_number' => $this->getSeatNumber(),
            'label' => $this->getLabel(),
            'state' => $this->getState(),
        ];
    }
}
