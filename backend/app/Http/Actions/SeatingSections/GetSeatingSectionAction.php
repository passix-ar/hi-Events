<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Seating\SeatingSectionResource;
use HiEvents\Services\Application\Handlers\SeatingSection\GetSeatingSectionHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GetSeatingSectionAction extends BaseAction
{
    public function __construct(
        private readonly GetSeatingSectionHandler $getSeatingSectionHandler,
    ) {}

    public function __invoke(int $eventId, int $seatingSectionId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $section = $this->getSeatingSectionHandler->handle($seatingSectionId, $eventId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: Response::HTTP_NOT_FOUND,
            );
        }

        return $this->resourceResponse(
            resource: SeatingSectionResource::class,
            data: $section,
        );
    }
}
