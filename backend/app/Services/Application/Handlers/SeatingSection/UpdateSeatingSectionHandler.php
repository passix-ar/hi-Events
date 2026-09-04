<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\UpsertSeatingSectionDTO;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use HiEvents\Services\Domain\Seating\UpdateSeatingSectionService;

class UpdateSeatingSectionHandler
{
    public function __construct(
        private readonly UpdateSeatingSectionService $updateSeatingSectionService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws UnrecognizedProductIdException
     * @throws InvalidSeatingLayoutException
     * @throws SeatingSectionInUseException
     */
    public function handle(UpsertSeatingSectionDTO $data): SeatingSectionDomainObject
    {
        $section = (new SeatingSectionDomainObject)
            ->setId($data->id)
            ->setName($data->name)
            ->setEventId($data->event_id)
            ->setProductId($data->product_id)
            ->setRowCount($data->row_count)
            ->setSeatsPerRow($data->seats_per_row)
            ->setStatus($data->status);

        return $this->updateSeatingSectionService->updateSeatingSection(
            $section,
            $data->disabled_seats,
            $data->aisle_positions,
        );
    }
}
