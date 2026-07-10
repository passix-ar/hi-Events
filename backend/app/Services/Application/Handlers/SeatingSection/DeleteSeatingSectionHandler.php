<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Seating\DeleteSeatingSectionService;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;

class DeleteSeatingSectionHandler
{
    public function __construct(
        private readonly DeleteSeatingSectionService $deleteSeatingSectionService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SeatingSectionInUseException
     */
    public function handle(int $sectionId, int $eventId): void
    {
        $this->deleteSeatingSectionService->deleteSeatingSection($sectionId, $eventId);
    }
}
