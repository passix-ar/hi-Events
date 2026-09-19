<?php

namespace HiEvents\Services\Domain\CheckInList;

use Exception;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\CheckInListDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
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
     * A list outside its activation window takes no check-ins and gives none back. Unlike the
     * per-attendee refusals below this one is about the request as a whole, so it throws.
     *
     * @throws CannotCheckInException
     */
    public function validateCheckInListIsAvailable(CheckInListDomainObject $checkInList): void
    {
        if ($checkInList->getExpiresAt() && DateHelper::utcDateIsPast($checkInList->getExpiresAt())) {
            throw new CannotCheckInException(__('Check-in list has expired'));
        }

        if ($checkInList->getActivatesAt() && DateHelper::utcDateIsFuture($checkInList->getActivatesAt())) {
            throw new CannotCheckInException(__('Check-in list is not active yet'));
        }
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
     * Scoped to the event, like the single-attendee lookup is. Without it a public_id from another
     * event resolves here, and although the product check below still refuses the check-in, the
     * refusal names the attendee — leaking a name out of an unrelated event to anyone holding a
     * check-in link.
     *
     * @return Collection<AttendeeDomainObject>
     * @throws Exception
     */
    public function getAttendees(Collection $attendeePublicIds, int $eventId): Collection
    {
        return $this->attendeeRepository->findWhereIn(
            field: AttendeeDomainObjectAbstract::PUBLIC_ID,
            values: array_unique($attendeePublicIds->toArray()),
            additionalWhere: [
                AttendeeDomainObjectAbstract::EVENT_ID => $eventId,
            ],
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
