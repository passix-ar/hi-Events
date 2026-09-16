<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Domain\CheckInList\CheckInListDataService;
use HiEvents\Services\Domain\CheckInList\CreateAttendeeCheckInService;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

/**
 * The door scanner sends check-ins in batches of up to 50. A refusal that belongs to one attendee
 * must stay with that attendee: when it took down the whole request, the scanner read the 409 as
 * definitive, dropped its queue and lost the check-ins of everyone who had already walked in.
 */
class CreateAttendeeCheckInServiceBatchIsolationTest extends TestCase
{
    private AttendeeCheckInRepositoryInterface $attendeeCheckInRepository;
    private CheckInListDataService $checkInListDataService;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private ConnectionInterface $db;
    private CreateAttendeeCheckInService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeCheckInRepository = m::mock(AttendeeCheckInRepositoryInterface::class);
        $this->checkInListDataService = m::mock(CheckInListDataService::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->db = m::mock(ConnectionInterface::class);

        $this->service = new CreateAttendeeCheckInService(
            $this->attendeeCheckInRepository,
            $this->checkInListDataService,
            $this->eventSettingsRepository,
            $this->db,
            m::mock(MarkOrderAsPaidService::class),
        );
    }

    public function testATicketFromAnotherListDoesNotSinkTheRestOfTheBatch(): void
    {
        $checkInList = $this->activeCheckInList();
        $general = $this->attendee('A-GENERAL', id: 10, productId: 2);
        $vip = $this->attendee('A-VIP', id: 11, productId: 99);

        $this->primeFlow($checkInList, collect([$general, $vip]));

        $this->checkInListDataService
            ->shouldReceive('validateAttendeeBelongsToCheckInList')
            ->with($checkInList, $general)
            ->once()
            ->andReturnNull();

        // The VIP ticket is not on this door's list: one attendee's refusal, not the batch's.
        $this->checkInListDataService
            ->shouldReceive('validateAttendeeBelongsToCheckInList')
            ->with($checkInList, $vip)
            ->once()
            ->andReturn('Attendee Vip Perez is not allowed to check in using this check-in list');

        $createdCheckIn = m::mock(AttendeeCheckInDomainObject::class);
        $this->attendeeCheckInRepository->shouldReceive('create')->once()->andReturn($createdCheckIn);

        $response = $this->service->checkInAttendees(
            'cil_test',
            '127.0.0.1',
            collect([
                new AttendeeAndActionDTO('A-GENERAL', AttendeeCheckInActionType::CHECK_IN),
                new AttendeeAndActionDTO('A-VIP', AttendeeCheckInActionType::CHECK_IN),
            ]),
        );

        $this->assertCount(1, $response->attendeeCheckIns);
        $this->assertSame($createdCheckIn, $response->attendeeCheckIns->first());
        $this->assertArrayHasKey('A-VIP', $response->errors->errors);
        $this->assertArrayNotHasKey('A-GENERAL', $response->errors->errors);
    }

    public function testACodeThatResolvesToNoAttendeeDoesNotSinkTheRestOfTheBatch(): void
    {
        $checkInList = $this->activeCheckInList();
        $general = $this->attendee('A-GENERAL', id: 10, productId: 2);

        // Only one of the two codes comes back from the lookup.
        $this->primeFlow($checkInList, collect([$general]));

        $this->checkInListDataService
            ->shouldReceive('validateAttendeeBelongsToCheckInList')
            ->once()
            ->andReturnNull();

        $createdCheckIn = m::mock(AttendeeCheckInDomainObject::class);
        $this->attendeeCheckInRepository->shouldReceive('create')->once()->andReturn($createdCheckIn);

        $response = $this->service->checkInAttendees(
            'cil_test',
            '127.0.0.1',
            collect([
                new AttendeeAndActionDTO('A-GENERAL', AttendeeCheckInActionType::CHECK_IN),
                new AttendeeAndActionDTO('A-NOPE', AttendeeCheckInActionType::CHECK_IN),
            ]),
        );

        $this->assertCount(1, $response->attendeeCheckIns);
        $this->assertArrayHasKey('A-NOPE', $response->errors->errors);
        $this->assertArrayNotHasKey('A-GENERAL', $response->errors->errors);
    }

    public function testAnExpiredListStillFailsTheWholeRequest(): void
    {
        // This one really is about the request, not about any attendee: the scanner is meant to
        // stop retrying and say so.
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->andReturn('2020-01-01 00:00:00');

        $this->checkInListDataService->shouldReceive('getCheckInList')->once()->andReturn($checkInList);
        $this->checkInListDataService->shouldNotReceive('getAttendees');

        $this->expectException(CannotCheckInException::class);

        $this->service->checkInAttendees(
            'cil_test',
            '127.0.0.1',
            collect([new AttendeeAndActionDTO('A-GENERAL', AttendeeCheckInActionType::CHECK_IN)]),
        );
    }

    private function activeCheckInList(): CheckInListDomainObject
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->andReturn(null);
        $checkInList->shouldReceive('getEventId')->andReturn(123);
        $checkInList->shouldReceive('getId')->andReturn(55);

        return $checkInList;
    }

    private function attendee(string $publicId, int $id, int $productId): AttendeeDomainObject
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getPublicId')->andReturn($publicId);
        $attendee->shouldReceive('getId')->andReturn($id);
        $attendee->shouldReceive('getProductId')->andReturn($productId);
        $attendee->shouldReceive('getOrderId')->andReturn(1);
        $attendee->shouldReceive('getEventId')->andReturn(123);
        $attendee->shouldReceive('getStatus')->andReturn(AttendeeStatus::ACTIVE->name);
        $attendee->shouldReceive('getFullName')->andReturn('Juan Perez');

        return $attendee;
    }

    /**
     * @param Collection<int, AttendeeDomainObject> $attendees what the public_id lookup resolves
     */
    private function primeFlow(CheckInListDomainObject $checkInList, Collection $attendees): void
    {
        $eventSettings = m::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getAllowOrdersAwaitingOfflinePaymentToCheckIn')->andReturn(false);

        $this->checkInListDataService->shouldReceive('getCheckInList')->once()->andReturn($checkInList);
        $this->checkInListDataService->shouldReceive('getAttendees')->once()->andReturn($attendees);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->once()->andReturn($eventSettings);
        $this->attendeeCheckInRepository->shouldReceive('findWhereIn')->once()->andReturn(new Collection());

        $this->db->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
    }
}
