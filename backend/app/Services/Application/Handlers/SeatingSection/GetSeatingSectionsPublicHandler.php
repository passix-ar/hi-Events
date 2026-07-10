<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use Illuminate\Support\Collection;

class GetSeatingSectionsPublicHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface           $seatRepository,
    )
    {
    }

    /**
     * @return Collection<int, SeatingSectionDomainObject>
     */
    public function handle(int $eventId): Collection
    {
        $sections = $this->seatingSectionRepository->findWhere([
            SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId,
            SeatingSectionDomainObjectAbstract::STATUS => SeatingSectionStatus::ACTIVE->name,
        ]);

        if ($sections->isEmpty()) {
            return $sections;
        }

        $seatsBySection = $this->seatRepository
            ->findByEventIdWithState(
                $eventId,
                $sections->map(static fn(SeatingSectionDomainObject $section) => $section->getId())->toArray(),
            )
            ->groupBy(static fn(SeatDomainObject $seat) => $seat->getSeatingSectionId());

        return $sections->map(
            static fn(SeatingSectionDomainObject $section) => $section->setSeats(
                $seatsBySection->get($section->getId()) ?? collect()
            )
        );
    }
}
