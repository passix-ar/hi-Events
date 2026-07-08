<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\Enums\SeatState;
use HiEvents\DomainObjects\Generated\SeatDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use Illuminate\Database\DatabaseManager;

class DeleteSeatingSectionService
{
    public function __construct(
        private readonly DatabaseManager                   $databaseManager,
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface           $seatRepository,
    )
    {
    }

    /**
     * @throws ResourceNotFoundException
     * @throws SeatingSectionInUseException
     */
    public function deleteSeatingSection(int $sectionId, int $eventId): void
    {
        $section = $this->seatingSectionRepository->findFirstWhere([
            SeatingSectionDomainObjectAbstract::ID => $sectionId,
            SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($section === null) {
            throw new ResourceNotFoundException(__('Seating section not found.'));
        }

        $hasOccupiedSeats = $this->seatRepository
            ->findByEventIdWithState($eventId, [$sectionId])
            ->contains(static fn(SeatDomainObject $seat) => $seat->getState() !== SeatState::AVAILABLE->name);

        if ($hasOccupiedSeats) {
            throw new SeatingSectionInUseException(
                __('This section cannot be deleted while seats are held or sold.')
            );
        }

        $this->databaseManager->transaction(function () use ($sectionId) {
            $this->seatRepository->deleteWhere([
                SeatDomainObjectAbstract::SEATING_SECTION_ID => $sectionId,
            ]);

            $this->seatingSectionRepository->deleteById($sectionId);
        });
    }
}
