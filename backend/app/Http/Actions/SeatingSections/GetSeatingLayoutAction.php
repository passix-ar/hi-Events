<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\SeatingLayoutDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingLayoutDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\SeatingLayoutRepositoryInterface;
use HiEvents\Resources\Seating\SeatingLayoutResource;
use Illuminate\Http\JsonResponse;

class GetSeatingLayoutAction extends BaseAction
{
    public function __construct(
        private readonly SeatingLayoutRepositoryInterface $seatingLayoutRepository,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $layout = $this->seatingLayoutRepository->findFirstWhere([
            SeatingLayoutDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        return $this->resourceResponse(
            resource: SeatingLayoutResource::class,
            // An event that has never been laid out still needs a stage to drag.
            data: $layout ?? (new SeatingLayoutDomainObject)->setStageX(0)->setStageY(-140)->setStageVisible(true),
        );
    }
}
