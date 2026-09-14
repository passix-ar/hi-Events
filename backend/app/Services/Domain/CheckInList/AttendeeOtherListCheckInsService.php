<?php

// Added by Passix on 2026-09-13: a ticket that already entered through another
// check-in list of the same event should be flagged at the door. Lists are
// independent by design (general vs VIP), so this is a warning, not a block.

namespace HiEvents\Services\Domain\CheckInList;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Support\Collection;

class AttendeeOtherListCheckInsService
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
    ) {}

    /**
     * Splits each attendee's loaded check-ins into "this list" and "other lists
     * of the event", with the list attached so the door can name it.
     *
     * @param  Collection<AttendeeDomainObject>  $attendees
     */
    public function attach(Collection $attendees, CheckInListDomainObject $currentList): void
    {
        $listsById = $this->checkInListRepository
            ->findWhere([CheckInListDomainObjectAbstract::EVENT_ID => $currentList->getEventId()])
            ->keyBy(fn (CheckInListDomainObject $list) => $list->getId());

        $attendees->each(function (AttendeeDomainObject $attendee) use ($currentList, $listsById) {
            $checkIns = $attendee->getCheckIns() ?? collect();

            $attendee->setCheckIn(
                $checkIns->first(fn (AttendeeCheckInDomainObject $checkIn) => $checkIn->getCheckInListId() === $currentList->getId())
            );

            $attendee->setOtherCheckIns(
                $checkIns
                    ->filter(fn (AttendeeCheckInDomainObject $checkIn) => $checkIn->getCheckInListId() !== $currentList->getId())
                    ->each(fn (AttendeeCheckInDomainObject $checkIn) => $checkIn->setCheckInList($listsById->get($checkIn->getCheckInListId())))
                    ->values()
            );
        });
    }
}
