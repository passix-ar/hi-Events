<?php

namespace HiEvents\Http\Actions\SeatingSections\Public;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Seating\SeatingSectionResourcePublic;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\GetSeatingSectionsPublicDTO;
use HiEvents\Services\Application\Handlers\SeatingSection\GetSeatingSectionsPublicHandler;
use Illuminate\Http\JsonResponse;

class GetSeatingSectionsActionPublic extends BaseAction
{
    public function __construct(
        private readonly GetSeatingSectionsPublicHandler $getSeatingSectionsPublicHandler,
    ) {}

    public function __invoke(int $eventId): JsonResponse
    {
        $isAuthenticated = $this->isUserAuthenticated();

        return $this->resourceResponse(
            resource: SeatingSectionResourcePublic::class,
            data: $this->getSeatingSectionsPublicHandler->handle(
                GetSeatingSectionsPublicDTO::from([
                    'event_id' => $eventId,
                    'authenticated_account_id' => $isAuthenticated ? $this->getAuthenticatedAccountId() : null,
                    'is_super_admin' => $isAuthenticated && $this->getAuthenticatedUserRole() === Role::SUPERADMIN,
                ]),
            ),
        );
    }
}
