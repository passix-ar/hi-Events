<?php

namespace HiEvents\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Domain\CheckInList\AttendeeOtherListCheckInsService;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetCheckInListAttendeePublicHandler
{
    public function __construct(
        private readonly AttendeeRepositoryInterface    $attendeeRepository,
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly AttendeeOtherListCheckInsService $otherListCheckInsService,
    )
    {
    }

    /**
     * @throws CannotCheckInException
     */
    public function handle(string $shortId, string $attendeePublicId): AttendeeDomainObject
    {
        $checkInList = $this->checkInListRepository
            ->loadRelation(ProductDomainObject::class)
            ->loadRelation(new Relationship(EventDomainObject::class, name: 'event'))
            ->findFirstWhere([
                CheckInListDomainObjectAbstract::SHORT_ID => $shortId,
            ]);

        if (!$checkInList) {
            throw new ResourceNotFoundException(__('Check-in list not found'));
        }

        $this->validateCheckInListIsActive($checkInList);

        // The product comes along because this lookup is how a ticket from another list of the
        // event reaches the scanner, and the door has to be told which one it is to send the person
        // to the right entrance. Named explicitly: the relation on the model is singular.
        $attendee = $this->attendeeRepository
            ->loadRelation(new Relationship(AttendeeCheckInDomainObject::class, name: 'check_ins'))
            ->loadRelation(new Relationship(ProductDomainObject::class, name: 'product'))
            ->findFirstWhere([
                'public_id' => $attendeePublicId,
                'event_id' => $checkInList->getEventId(),
            ]);

        if ($attendee) {
            $this->otherListCheckInsService->attach(collect([$attendee]), $checkInList);
        }

        return $attendee;
    }

    /**
     * @todo - Move this to its own service. It's used 3 times
     * @throws CannotCheckInException
     */
    private function validateCheckInListIsActive(CheckInListDomainObject $checkInList): void
    {
        if ($checkInList->getExpiresAt() && DateHelper::utcDateIsPast($checkInList->getExpiresAt())) {
            throw new CannotCheckInException(__('Check-in list has expired'));
        }

        if ($checkInList->getActivatesAt() && DateHelper::utcDateIsFuture($checkInList->getActivatesAt())) {
            throw new CannotCheckInException(__('Check-in list is not active yet'));
        }
    }
}
