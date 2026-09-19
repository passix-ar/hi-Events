<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Domain\CheckInList\AttendeeOtherListCheckInsService;
use HiEvents\Services\Domain\CheckInList\CheckInListDataService;
use Illuminate\Contracts\Pagination\Paginator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetCheckInListAttendeesPublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface    $attendeeRepository,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly AttendeeOtherListCheckInsService $otherListCheckInsService,
        private readonly CheckInListDataService $checkInListDataService,
    )
    {
    }

    /**
     * @throws CannotCheckInException
     */
    public function handle(string $shortId, QueryParamsDTO $queryParams): Paginator
    {
        // No relations: nothing here reads the list's products or its event, only the event id it
        // carries as a column. This is the request the scanner repeats for every page of every
        // refresh, all night, so two eager loads it never touches are worth removing.
        $checkInList = $this->checkInListRepository
            ->findFirstWhere([
                CheckInListDomainObjectAbstract::SHORT_ID => $shortId,
            ]);

        if (!$checkInList) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        $this->checkInListDataService->validateCheckInListIsAvailable($checkInList);

        $attendees = $this->attendeeRepository->getAttendeesByCheckInShortId($shortId, $queryParams);

        $this->otherListCheckInsService->attach($attendees->getCollection(), $checkInList);

        return $attendees;
    }
}
