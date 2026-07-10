<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UpsertSeatingSectionDTO extends BaseDataObject
{
    public function __construct(
        public string $name,
        public int $event_id,
        public int $product_id,
        public int $row_count,
        public int $seats_per_row,
        public string $status,
        public ?array $disabled_seats = null,
        public ?int $id = null,
    ) {}
}
