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
     * Saves the arrangement the organizer laid out: where each section sits relative to the
     * stage, and its order within that spot. Ids that do not belong to the event are ignored,
     * and any section left out of the payload keeps what it had.
     *
     * @param  array<int, array{id: int, layout_position: string}>  $arrangement
     * @return Collection<int, SeatingSectionDomainObject>
     */
    public function handle(int $eventId, array $arrangement): Collection
    {
        $ownIds = $this->seatingSectionRepository
            ->findWhere([SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId])
            ->map(static fn (SeatingSectionDomainObject $section) => $section->getId())
            ->all();

        $position = 0;

        foreach ($arrangement as $entry) {
            if (! in_array((int) $entry['id'], $ownIds, true)) {
                continue;
            }

            $this->seatingSectionRepository->updateFromArray((int) $entry['id'], [
                SeatingSectionDomainObjectAbstract::ORDER => $position++,
                SeatingSectionDomainObjectAbstract::LAYOUT_POSITION => $entry['layout_position'],
            ]);
        }

        return $this->seatingSectionRepository->findWhere(
            where: [SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId],
            orderAndDirections: [new OrderAndDirection(SeatingSectionDomainObjectAbstract::ORDER)],
        );
    }
}
