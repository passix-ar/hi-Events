<?php

namespace HiEvents\Services\Domain\CheckInList;

use Exception;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Support\Collection;

class CheckInListDataService
{
    public function __construct(
        private readonly CheckInListRepositoryInterface $checkInListRepository,
        private readonly AttendeeRepositoryInterface    $attendeeRepository,
    )
    {
    }

    /**
     * A ticket this list does not cover is a refusal for that attendee alone, so it is returned as
     * a message instead of thrown: the rest of the batch still has to get through.
     */
    public function validateAttendeeBelongsToCheckInList(
        CheckInListDomainObject $checkInList,
        AttendeeDomainObject    $attendee,
    ): ?string
    {
        $allowedProductIds = $checkInList->getProducts()->map(fn($product) => $product->getId())->toArray() ?? [];

        if (!in_array($attendee->getProductId(), $allowedProductIds, true)) {
            return __('Attendee :attendee_name is not allowed to check in using this check-in list', [
                'attendee_name' => $attendee->getFullName(),
            ]);
        }

        return null;
    }

    /**
     * Returns whatever resolved. A code that matches no attendee is that scan's problem, not the
     * batch's: the caller holds the error bag and reports the missing ones there.
     *
     * @return Collection<AttendeeDomainObject>
     * @throws Exception
     */
    public function getAttendees(Collection $attendeePublicIds): Collection
    {
        return $this->attendeeRepository->findWhereIn(
            field: AttendeeDomainObjectAbstract::PUBLIC_ID,
            values: array_unique($attendeePublicIds->toArray()),
        );
    }

    /**
     * @throws CannotCheckInException
     */
    public function getCheckInList(string $checkInListUuid): CheckInListDomainObject
    {
        $checkInList = $this->checkInListRepository
            ->loadRelation(ProductDomainObject::class)
            ->findFirstWhere([
                CheckInListDomainObjectAbstract::SHORT_ID => $checkInListUuid,
            ]);

        if ($checkInList === null) {
            throw new CannotCheckInException(__('Check-in list not found'));
        }

        return $checkInList;
    }
}
