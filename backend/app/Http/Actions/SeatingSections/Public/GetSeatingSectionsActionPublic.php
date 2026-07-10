<?php

namespace HiEvents\Http\Actions\SeatingSections\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Seating\SeatingSectionResourcePublic;
use HiEvents\Services\Application\Handlers\SeatingSection\GetSeatingSectionsPublicHandler;
use Illuminate\Http\JsonResponse;

class GetSeatingSectionsActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetSeatingSectionsPublicHandler $getSeatingSectionsPublicHandler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        return $this->resourceResponse(
            resource: SeatingSectionResourcePublic::class,
            data: $this->getSeatingSectionsPublicHandler->handle($eventId),
        );
    }
}
