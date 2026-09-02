<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\SeatingSection\ReorderSeatingSectionsRequest;
use HiEvents\Resources\Seating\SeatingSectionResource;
use HiEvents\Services\Application\Handlers\SeatingSection\ReorderSeatingSectionsHandler;
use Illuminate\Http\JsonResponse;

class ReorderSeatingSectionsAction extends BaseAction
{
    public function __construct(
        private readonly ReorderSeatingSectionsHandler $reorderSeatingSectionsHandler,
    ) {}

    public function __invoke(int $eventId, ReorderSeatingSectionsRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return $this->resourceResponse(
            resource: SeatingSectionResource::class,
            data: $this->reorderSeatingSectionsHandler->handle(
                $eventId,
                $request->validated('section_ids'),
            ),
        );
    }
}
