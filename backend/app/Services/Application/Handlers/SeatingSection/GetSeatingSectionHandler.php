<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;

class GetSeatingSectionHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface $seatRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $sectionId, int $eventId): SeatingSectionDomainObject
    {
        /** @var SeatingSectionDomainObject|null $section */
        $section = $this->seatingSectionRepository
            ->loadRelation(new Relationship(domainObject: ProductDomainObject::class, name: 'product'))
            ->findFirstWhere([
                SeatingSectionDomainObjectAbstract::ID => $sectionId,
                SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId,
            ]);

        if ($section === null) {
            throw new ResourceNotFoundException(__('Seating section not found.'));
        }

        $section->setSeats(
            $this->seatRepository->findByEventIdWithState($eventId, [$sectionId])
        );

        return $section;
    }
}
