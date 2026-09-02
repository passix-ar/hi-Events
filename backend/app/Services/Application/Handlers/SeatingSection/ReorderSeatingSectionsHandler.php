<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use Illuminate\Support\Collection;

class ReorderSeatingSectionsHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
    ) {}

    /**
     * Lays the event's sections out front to back. Ids that do not belong to the event are
     * ignored, and any section the caller left out keeps its place at the end.
     *
     * @param  array<int, int>  $sectionIds
     * @return Collection<int, SeatingSectionDomainObject>
     */
    public function handle(int $eventId, array $sectionIds): Collection
    {
        $sections = $this->seatingSectionRepository->findWhere([
            SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        $requested = array_values(array_intersect(
            $sectionIds,
            $sections->map(static fn (SeatingSectionDomainObject $section) => $section->getId())->all(),
        ));

        foreach ($requested as $position => $sectionId) {
            $this->seatingSectionRepository->updateFromArray($sectionId, [
                SeatingSectionDomainObjectAbstract::ORDER => $position,
            ]);
        }

        return $this->seatingSectionRepository->findWhere(
            where: [SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId],
            orderAndDirections: [new OrderAndDirection(SeatingSectionDomainObjectAbstract::ORDER)],
        );
    }
}
