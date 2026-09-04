<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\Generated\SeatingLayoutDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingLayoutDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Interfaces\SeatingLayoutRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;

class SaveSeatingLayoutHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatingLayoutRepositoryInterface $seatingLayoutRepository,
    ) {}

    /**
     * Stores where the organizer dropped everything: the stage, and each section's spot on
     * the canvas. Ids that do not belong to the event are ignored, and a section left out of
     * the payload keeps where it was.
     *
     * @param  array<int, array{id: int, position_x: int, position_y: int}>  $sections
     */
    public function handle(int $eventId, int $stageX, int $stageY, bool $stageVisible, array $sections): SeatingLayoutDomainObject
    {
        $ownIds = $this->seatingSectionRepository
            ->findWhere([SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId])
            ->map(static fn (SeatingSectionDomainObject $section) => $section->getId())
            ->all();

        foreach ($sections as $section) {
            if (! in_array((int) $section['id'], $ownIds, true)) {
                continue;
            }

            $this->seatingSectionRepository->updateFromArray((int) $section['id'], [
                SeatingSectionDomainObjectAbstract::POSITION_X => (int) $section['position_x'],
                SeatingSectionDomainObjectAbstract::POSITION_Y => (int) $section['position_y'],
            ]);
        }

        $existing = $this->seatingLayoutRepository->findFirstWhere([
            SeatingLayoutDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        $attributes = [
            SeatingLayoutDomainObjectAbstract::STAGE_X => $stageX,
            SeatingLayoutDomainObjectAbstract::STAGE_Y => $stageY,
            SeatingLayoutDomainObjectAbstract::STAGE_VISIBLE => $stageVisible,
        ];

        return $existing
            ? $this->seatingLayoutRepository->updateFromArray($existing->getId(), $attributes)
            : $this->seatingLayoutRepository->create($attributes + [
                SeatingLayoutDomainObjectAbstract::EVENT_ID => $eventId,
            ]);
    }
}
