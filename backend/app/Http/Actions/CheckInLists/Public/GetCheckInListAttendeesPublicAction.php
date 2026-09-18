<?php

namespace HiEvents\Http\Actions\CheckInLists\Public;

use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Resources\Attendee\AttendeeWithCheckInPublicResource;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListAttendeesPublicHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GetCheckInListAttendeesPublicAction extends BaseAction
{
    public function __construct(
        private readonly GetCheckInListAttendeesPublicHandler $getCheckInListAttendeesPublicHandler,
    )
    {
    }

    public function __invoke(string $checkInListShortId, Request $request): JsonResponse
    {
        try {
            $attendees = $this->getCheckInListAttendeesPublicHandler->handle(
                shortId: $checkInListShortId,
                queryParams: QueryParamsDTO::fromArray($this->paginationParams($request))
            );
        } catch (CannotCheckInException $e) {
            return $this->errorResponse(
                message: $e->getMessage(),
                statusCode: Response::HTTP_FORBIDDEN,
            );
        }

        return $this->resourceResponse(
            resource: AttendeeWithCheckInPublicResource::class,
            data: $attendees,
        );
    }

    /**
     * The scanner walks this endpoint page by page, and both numbers come straight off the URL of a
     * public route: `QueryParamsDTO` only casts them. A negative per_page survived the repository's
     * `min()` untouched and reached the query builder, which drops a negative limit instead of
     * applying it — so `?per_page=-1` answered with the entire roster in one response, joins and
     * check-ins included, straight past the 250 cap. The upper bound stays with the repository,
     * which owns that cap; this only establishes the floor.
     */
    private function paginationParams(Request $request): array
    {
        $query = $request->query->all();

        $query['page'] = max(1, (int)($query['page'] ?? 1));
        $query['per_page'] = max(1, (int)($query['per_page'] ?? 25));

        return $query;
    }
}
