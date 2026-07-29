<?php

namespace HiEvents\Http\Actions\SeatingSections;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\SeatingSection\UpsertSeatingSectionRequest;
use HiEvents\Resources\Seating\SeatingSectionResource;
use HiEvents\Services\Application\Handlers\SeatingSection\CreateSeatingSectionHandler;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\UpsertSeatingSectionDTO;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateSeatingSectionAction extends BaseAction
{
    public function __construct(
        private readonly CreateSeatingSectionHandler $createSeatingSectionHandler,
    ) {}

    public function __invoke(int $eventId, UpsertSeatingSectionRequest $request): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        try {
            $section = $this->createSeatingSectionHandler->handle(
                UpsertSeatingSectionDTO::from([
                    'name' => $request->validated('name'),
                    'event_id' => $eventId,
                    'product_id' => $request->validated('product_id'),
                    'row_count' => $request->validated('row_count'),
                    'seats_per_row' => $request->validated('seats_per_row'),
                    'status' => $request->validated('status'),
                    'disabled_seats' => $request->validated('disabled_seats'),
                ]),
            );
        } catch (UnrecognizedProductIdException|InvalidSeatingLayoutException $exception) {
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
