<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;

class UpsertSeatingSectionDTO extends BaseDataObject
{
    public function __construct(
        public string               $name,
        public int                  $event_id,
        public int                  $product_id,
        public int                  $row_count,
        public int                  $seats_per_row,
        public SeatingSectionStatus $status,
        public ?int                 $id = null,
    )
    {
    }
}
