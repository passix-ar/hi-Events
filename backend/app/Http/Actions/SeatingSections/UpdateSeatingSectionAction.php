<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\SeatingSection\UpsertSeatingSectionRequest;
use HiEvents\Resources\Seating\SeatingSectionResource;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\UpsertSeatingSectionDTO;
use HiEvents\Services\Application\Handlers\SeatingSection\UpdateSeatingSectionHandler;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UpdateSeatingSectionAction extends BaseAction
{
    public function __construct(
        private readonly UpdateSeatingSectionHandler $updateSeatingSectionHandler,
    ) {}

    public function __invoke(int $eventId, int $seatingSectionId, UpsertSeatingSectionRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $section = $this->updateSeatingSectionHandler->handle(
                UpsertSeatingSectionDTO::from([
                    'id' => $seatingSectionId,
                    'name' => $request->validated('name'),
                    'event_id' => $eventId,
                    'product_id' => $request->validated('product_id'),
                    'row_count' => $request->validated('row_count'),
                    'seats_per_row' => $request->validated('seats_per_row'),
                    'status' => $request->validated('status'),
                    'disabled_seats' => $request->validated('disabled_seats'),
                    'aisle_positions' => $request->validated('aisle_positions'),
                    'layout_position' => $request->validated('layout_position'),
                ]),
            );
        } catch (ResourceNotFoundException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: Response::HTTP_NOT_FOUND,
            );
        } catch (UnrecognizedProductIdException|InvalidSeatingLayoutException|SeatingSectionInUseException $exception) {
            return $this->errorResponse(
                message: $exception->getMessage(),
                statusCode: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->resourceResponse(
            resource: SeatingSectionResource::class,
            data: $section,
        );
    }
}
