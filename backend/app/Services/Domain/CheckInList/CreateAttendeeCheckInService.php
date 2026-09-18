<?php

namespace HiEvents\Services\Domain\CheckInList;

use Exception;
use HiEvents\DataTransferObjects\ErrorBagDTO;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeCheckInDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Helper\DateHelper;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Domain\CheckInList\DTO\CheckInResultDTO;
use HiEvents\Services\Domain\CheckInList\DTO\CreateAttendeeCheckInsResponseDTO;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Throwable;

class CreateAttendeeCheckInService
{
    public function __construct(
        private readonly AttendeeCheckInRepositoryInterface $attendeeCheckInRepository,
        private readonly CheckInListDataService             $checkInListDataService,
        private readonly EventSettingsRepositoryInterface   $eventSettingsRepository,
        private readonly ConnectionInterface                $db,
        private readonly MarkOrderAsPaidService             $markOrderAsPaidService,
        private readonly OrderRepositoryInterface           $orderRepository,
    )
    {
    }

    /**
     * @param string $checkInListUuid
     * @param string $checkInUserIpAddress
     * @param Collection<int, AttendeeAndActionDTO> $attendeesAndActions
     * @return CreateAttendeeCheckInsResponseDTO
     * @throws CannotCheckInException
     * @throws Exception|Throwable
     */
    public function checkInAttendees(
        string     $checkInListUuid,
        string     $checkInUserIpAddress,
        Collection $attendeesAndActions
    ): CreateAttendeeCheckInsResponseDTO
    {
        $checkInList = $this->checkInListDataService->getCheckInList($checkInListUuid);
        $this->validateCheckInListIsActive($checkInList);

        $attendees = $this->fetchAttendees($attendeesAndActions);
        $eventSettings = $this->fetchEventSettings($checkInList->getEventId());
        $existingCheckIns = $this->fetchExistingCheckIns($attendees, $checkInList);

        return $this->processAttendeeCheckIns(
            $attendees,
            $attendeesAndActions,
            $checkInList,
            $eventSettings,
            $existingCheckIns,
            $checkInUserIpAddress
        );
    }

    /**
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

    /**
     * @param Collection<int, AttendeeAndActionDTO> $attendeesAndActions
     * @return Collection<int, AttendeeDomainObject>
     * @throws CannotCheckInException
     */
    private function fetchAttendees(Collection $attendeesAndActions): Collection
    {
        $publicIds = $attendeesAndActions->map(
            fn(AttendeeAndActionDTO $attendeeAndAction) => $attendeeAndAction->public_id
        );
        return $this->checkInListDataService->getAttendees($publicIds);
    }

    private function fetchEventSettings(int $eventId): EventSettingDomainObject
    {
        return $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $eventId,
        ]);
    }

    /**
     * @param Collection<int, AttendeeDomainObject> $attendees
     * @param CheckInListDomainObject $checkInList
     * @return Collection
     * @throws Exception
     */
    private function fetchExistingCheckIns(Collection $attendees, CheckInListDomainObject $checkInList): Collection
    {
        $attendeeIds = $attendees->map(fn(AttendeeDomainObject $attendee) => $attendee->getId())->toArray();

        return $this->attendeeCheckInRepository->findWhereIn(
            field: AttendeeCheckInDomainObjectAbstract::ATTENDEE_ID,
            values: $attendeeIds,
            additionalWhere: [
                AttendeeCheckInDomainObjectAbstract::EVENT_ID => $checkInList->getEventId(),
                AttendeeCheckInDomainObjectAbstract::CHECK_IN_LIST_ID => $checkInList->getId(),
            ],
        );
    }

    /**
     * @throws Throwable
     */
    private function processAttendeeCheckIns(
        Collection               $attendees,
        Collection               $attendeesAndActions,
        CheckInListDomainObject  $checkInList,
        EventSettingDomainObject $eventSettings,
        Collection               $existingCheckIns,
        string                   $checkInUserIpAddress
    ): CreateAttendeeCheckInsResponseDTO
    {
        $errors = new ErrorBagDTO();
        $checkIns = new Collection();

        foreach ($attendees as $attendee) {
            $result = $this->processIndividualCheckIn(
                $attendee,
                $attendeesAndActions,
                $checkInList,
                $eventSettings,
                $existingCheckIns,
                $checkInUserIpAddress
            );

            if ($result->checkIn) {
                $checkIns->push($result->checkIn);
            }
            if ($result->error) {
                $errors->addError($attendee->getPublicId(), $result->error);
            }
        }

        // A code that resolved to no attendee is that one scan's problem, not the batch's: it is
        // reported against its own public_id so every other check-in in the request still lands.
        $resolved = $attendees->map(fn(AttendeeDomainObject $attendee) => $attendee->getPublicId())->all();
        foreach ($attendeesAndActions as $attendeeAndAction) {
            if (!in_array($attendeeAndAction->public_id, $resolved, true)) {
                $errors->addError($attendeeAndAction->public_id, __('Invalid attendee code detected: :attendees ', [
                    'attendees' => $attendeeAndAction->public_id,
                ]));
            }
        }

        return new CreateAttendeeCheckInsResponseDTO(
            attendeeCheckIns: $checkIns,
            errors: $errors,
        );
    }

    /**
     * @throws Throwable
     */
    private function processIndividualCheckIn(
        AttendeeDomainObject     $attendee,
        Collection               $attendeesAndActions,
        CheckInListDomainObject  $checkInList,
        EventSettingDomainObject $eventSettings,
        Collection               $existingCheckIns,
        string                   $checkInUserIpAddress
    ): CheckInResultDTO
    {
        if ($error = $this->checkInListDataService->validateAttendeeBelongsToCheckInList($checkInList, $attendee)) {
            return new CheckInResultDTO(error: $error);
        }

        $attendeeAction = $attendeesAndActions->first(
            fn(AttendeeAndActionDTO $action) => $action->public_id === $attendee->getPublicId()
        );
        $checkInAction = $attendeeAction->action;

        if ($existingCheckIn = $this->getExistingCheckIn($existingCheckIns, $attendee)) {
            return new CheckInResultDTO(
                checkIn: $existingCheckIn,
                error: __('Attendee :attendee_name is already checked in', [
                    'attendee_name' => $attendee->getFullName(),
                ])
            );
        }

        if ($error = $this->validateAttendeeStatus($attendee, $checkInAction, $eventSettings)) {
            return new CheckInResultDTO(error: $error);
        }

        $markOrderAsPaid = $checkInAction->value === AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID->value;

        if ($markOrderAsPaid) {
            // Every attendee in this request was read before any of them were processed, so their
            // copy of the order is as old as the request. Another ticket on the same order — one
            // earlier in this very batch, or one the phone sent before its roster caught up — may
            // have paid it already, and paying it twice throws. Scoped to the event as well: this
            // sits behind a public endpoint whose only secret is a shareable short id, so an order
            // must never be readable from another event.
            $orderStatus = $this->orderRepository->findFirstWhere([
                OrderDomainObjectAbstract::ID => $attendee->getOrderId(),
                OrderDomainObjectAbstract::EVENT_ID => $attendee->getEventId(),
            ])?->getStatus();

            if ($orderStatus === OrderStatus::COMPLETED->name) {
                // Paid is all the scan was asking for, so the person walks in and only the redundant
                // payment step is skipped. Refusing here would show red at the door to someone whose
                // order is settled.
                $markOrderAsPaid = false;
            } elseif ($orderStatus !== OrderStatus::AWAITING_OFFLINE_PAYMENT->name) {
                return new CheckInResultDTO(error: __('Order is not awaiting offline payment'));
            }
        }

        try {
            return $this->db->transaction(function () use ($attendee, $checkInList, $markOrderAsPaid, $checkInUserIpAddress) {
                $checkIn = $this->createCheckIn($attendee, $checkInList, $checkInUserIpAddress);

                if ($markOrderAsPaid) {
                    $this->markOrderAsPaidService->markOrderAsPaid(
                        orderId: $attendee->getOrderId(),
                        eventId: $attendee->getEventId(),
                    );
                }

                return new CheckInResultDTO(checkIn: $checkIn);
            });
        } catch (QueryException $exception) {
            // A concurrent request won the race and created the active check-in between our read
            // and our insert. The partial unique index on (attendee_id, check_in_list_id) rejects
            // ours (SQLSTATE 23505), and the transaction — including any mark-as-paid — is rolled
            // back. Report it as already-checked-in, mirroring the pre-checked path. Any other
            // database error is re-thrown so a real failure is never reported as a check-in.
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            $existingCheckIn = $this->attendeeCheckInRepository->findFirstWhere([
                AttendeeCheckInDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
                AttendeeCheckInDomainObjectAbstract::CHECK_IN_LIST_ID => $checkInList->getId(),
            ]);

            return new CheckInResultDTO(
                checkIn: $existingCheckIn,
                error: __('Attendee :attendee_name is already checked in', [
                    'attendee_name' => $attendee->getFullName(),
                ])
            );
        } catch (ResourceConflictException $exception) {
            // Two requests got past the status check above and the other one paid the order first.
            // This has to come back as one person's error: left to escape it renders as a 500 — the
            // exception carries a 409 code but extends a plain Exception, so nothing maps it — and
            // the scanner treats 500 as retryable, resending the same batch every thirty seconds
            // for the rest of the night without a single check-in ever landing.
            return new CheckInResultDTO(error: $exception->getMessage());
        }
    }

    private function getExistingCheckIn(Collection $existingCheckIns, AttendeeDomainObject $attendee): ?object
    {
        return $existingCheckIns->first(
            fn($checkIn) => $checkIn->getAttendeeId() === $attendee->getId()
        );
    }

    private function validateAttendeeStatus(
        AttendeeDomainObject      $attendee,
        AttendeeCheckInActionType $checkInAction,
        EventSettingDomainObject  $eventSettings
    ): ?string
    {
        $allowAttendeesAwaitingPaymentToCheckIn = $eventSettings->getAllowOrdersAwaitingOfflinePaymentToCheckIn();

        if ($attendee->getStatus() === AttendeeStatus::CANCELLED->name) {
            return __('Attendee :attendee_name\'s ticket is cancelled', [
                'attendee_name' => $attendee->getFullName(),
            ]);
        }

        if (!$allowAttendeesAwaitingPaymentToCheckIn) {
            if ($checkInAction->value === AttendeeCheckInActionType::CHECK_IN->value
                && $attendee->getStatus() === AttendeeStatus::AWAITING_PAYMENT->name
            ) {
                return __('Unable to check in as attendee :attendee_name\'s order is awaiting payment', [
                    'attendee_name' => $attendee->getFullName(),
                ]);
            }

            if ($checkInAction->value === AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID->value) {
                return __('Attendee :attendee_name\'s order cannot be marked as paid. Please check your event settings', [
                    'attendee_name' => $attendee->getFullName(),
                ]);
            }
        }

        return null;
    }

    private function createCheckIn(
        AttendeeDomainObject    $attendee,
        CheckInListDomainObject $checkInList,
        string                  $checkInUserIpAddress
    ): AttendeeCheckInDomainObject
    {
        return $this->attendeeCheckInRepository->create([
            AttendeeCheckInDomainObjectAbstract::ORDER_ID => $attendee->getOrderId(),
            AttendeeCheckInDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
            AttendeeCheckInDomainObjectAbstract::CHECK_IN_LIST_ID => $checkInList->getId(),
            AttendeeCheckInDomainObjectAbstract::IP_ADDRESS => $checkInUserIpAddress,
            AttendeeCheckInDomainObjectAbstract::PRODUCT_ID => $attendee->getProductId(),
            AttendeeCheckInDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::CHECK_IN_PREFIX),
            AttendeeCheckInDomainObjectAbstract::EVENT_ID => $checkInList->getEventId(),
        ]);
    }
}
