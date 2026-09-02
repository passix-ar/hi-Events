<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\GetSeatingSectionsPublicDTO;
use Illuminate\Support\Collection;

class GetSeatingSectionsPublicHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @return Collection<int, SeatingSectionDomainObject>
     *
     * @throws ResourceNotFoundException
     */
    public function handle(GetSeatingSectionsPublicDTO $dto): Collection
    {
        $event = $this->eventRepository->findById($dto->event_id);

        if ($event === null || ! $this->canViewEvent($event, $dto)) {
            throw new ResourceNotFoundException;
        }

        $sections = $this->seatingSectionRepository->findWhere([
            SeatingSectionDomainObjectAbstract::EVENT_ID => $dto->event_id,
            SeatingSectionDomainObjectAbstract::STATUS => SeatingSectionStatus::ACTIVE->name,
        ]);

        if ($sections->isEmpty()) {
            return $sections;
        }

        $seatsBySection = $this->seatRepository
            ->findByEventIdWithState(
                $dto->event_id,
                $sections->map(static fn (SeatingSectionDomainObject $section) => $section->getId())->toArray(),
            )
            ->groupBy(static fn (SeatDomainObject $seat) => $seat->getSeatingSectionId());

        return $sections->map(
            static fn (SeatingSectionDomainObject $section) => $section->setSeats(
                $seatsBySection->get($section->getId()) ?? collect()
            )
        );
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
