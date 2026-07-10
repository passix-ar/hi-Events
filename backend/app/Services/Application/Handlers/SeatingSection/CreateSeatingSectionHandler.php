<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\UpsertSeatingSectionDTO;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\CreateSeatingSectionService;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;

class CreateSeatingSectionHandler
{
    public function __construct(
        private readonly CreateSeatingSectionService $createSeatingSectionService,
    )
    {
    }

    /**
     * @throws UnrecognizedProductIdException
     * @throws InvalidSeatingLayoutException
     */
    public function handle(UpsertSeatingSectionDTO $data): SeatingSectionDomainObject
    {
        $section = (new SeatingSectionDomainObject)
            ->setName($data->name)
            ->setEventId($data->event_id)
            ->setProductId($data->product_id)
            ->setRowCount($data->row_count)
            ->setSeatsPerRow($data->seats_per_row)
            ->setStatus($data->status);

        return $this->createSeatingSectionService->createSeatingSection($section);
    }
}
