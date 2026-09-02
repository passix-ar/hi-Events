<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SeatingLayoutDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingLayoutRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\GetSeatingSectionsPublicDTO;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\SeatingPlanDTO;
use Illuminate\Support\Collection;

class GetSeatingSectionsPublicHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly SeatingLayoutRepositoryInterface $seatingLayoutRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(GetSeatingSectionsPublicDTO $dto): SeatingPlanDTO
    {
        $event = $this->eventRepository->findById($dto->event_id);

        if ($event === null || ! $this->canViewEvent($event, $dto)) {
            throw new ResourceNotFoundException;
        }

        $sections = $this->seatingSectionRepository->findWhere(
            where: [
                SeatingSectionDomainObjectAbstract::EVENT_ID => $dto->event_id,
                SeatingSectionDomainObjectAbstract::STATUS => SeatingSectionStatus::ACTIVE->name,
            ],
            orderAndDirections: [new OrderAndDirection(SeatingSectionDomainObjectAbstract::ORDER)],
        );

        $layout = $this->seatingLayoutRepository->findFirstWhere([
            SeatingLayoutDomainObjectAbstract::EVENT_ID => $dto->event_id,
        ]);

        $plan = static fn (Collection $withSeats) => new SeatingPlanDTO(
            stage_x: $layout?->getStageX() ?? 0,
            stage_y: $layout?->getStageY() ?? -140,
            sections: $withSeats,
        );

        if ($sections->isEmpty()) {
            return $plan($sections);
        }

        $seatsBySection = $this->seatRepository
            ->findByEventIdWithState(
                $dto->event_id,
                $sections->map(static fn (SeatingSectionDomainObject $section) => $section->getId())->toArray(),
            )
            ->groupBy(static fn (SeatDomainObject $seat) => $seat->getSeatingSectionId());

        return $plan($sections->map(
            static fn (SeatingSectionDomainObject $section) => $section->setSeats(
                $seatsBySection->get($section->getId()) ?? collect()
            )
        ));
    }

    /**
     * Mirrors GetEventPublicAction: the seat map of an event that is not live is only
     * visible to the account that owns it, so an organizer can still preview it.
     */
    private function canViewEvent(EventDomainObject $event, GetSeatingSectionsPublicDTO $dto): bool
    {
        if ($event->getStatus() === EventStatus::LIVE->name) {
            return true;
        }

        if ($dto->is_super_admin) {
            return true;
        }

        return $dto->authenticated_account_id !== null
            && $event->getAccountId() === $dto->authenticated_account_id;
    }
}
