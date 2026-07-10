<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\SeatingSection\DeleteSeatingSectionHandler;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DeleteSeatingSectionAction extends BaseAction
{
    public function __construct(
        private readonly DeleteSeatingSectionHandler $deleteSeatingSectionHandler,
    )
    {
    }

    public function __invoke(int $eventId, int $seatingSectionId): Response|JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $this->deleteSeatingSectionHandler->handle($seatingSectionId, $eventId);
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: SymfonyResponse::HTTP_NOT_FOUND,
            );
        } catch (SeatingSectionInUseException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->noContentResponse();
    }
}
