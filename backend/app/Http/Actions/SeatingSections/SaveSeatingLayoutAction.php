<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\SeatingSection\SaveSeatingLayoutRequest;
use HiEvents\Resources\Seating\SeatingLayoutResource;
use HiEvents\Services\Application\Handlers\SeatingSection\SaveSeatingLayoutHandler;
use Illuminate\Http\JsonResponse;

class SaveSeatingLayoutAction extends BaseAction
{
    public function __construct(
        private readonly SaveSeatingLayoutHandler $saveSeatingLayoutHandler,
    ) {}

    public function __invoke(int $eventId, SaveSeatingLayoutRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        return $this->resourceResponse(
            resource: SeatingLayoutResource::class,
            data: $this->saveSeatingLayoutHandler->handle(
                $eventId,
                (int) $request->validated('stage_x'),
                (int) $request->validated('stage_y'),
                (bool) $request->validated('stage_visible'),
                $request->validated('sections'),
            ),
        );
    }
}
