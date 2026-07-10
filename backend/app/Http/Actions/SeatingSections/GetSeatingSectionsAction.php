<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Seating\SeatingSectionResource;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\GetSeatingSectionsDTO;
use HiEvents\Services\Application\Handlers\SeatingSection\GetSeatingSectionsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetSeatingSectionsAction extends BaseAction
{
    public function __construct(
        private readonly GetSeatingSectionsHandler $getSeatingSectionsHandler,
    )
    {
    }

    public function __invoke(int $eventId, Request $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return $this->filterableResourceResponse(
            resource: SeatingSectionResource::class,
            data: $this->getSeatingSectionsHandler->handle(
                new GetSeatingSectionsDTO(
                    eventId: $eventId,
                    queryParams: $this->getPaginationQueryParams($request),
                ),
            ),
            domainObject: SeatingSectionDomainObject::class,
        );
    }
}
