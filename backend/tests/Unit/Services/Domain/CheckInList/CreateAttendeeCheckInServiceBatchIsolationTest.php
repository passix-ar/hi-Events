<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
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
    private MarkOrderAsPaidService $markOrderAsPaidService;
    private OrderRepositoryInterface $orderRepository;
    private CreateAttendeeCheckInService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeCheckInRepository = m::mock(AttendeeCheckInRepositoryInterface::class);
        $this->checkInListDataService = m::mock(CheckInListDataService::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->db = m::mock(ConnectionInterface::class);
        $this->markOrderAsPaidService = m::mock(MarkOrderAsPaidService::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);

        $this->service = new CreateAttendeeCheckInService(
            $this->attendeeCheckInRepository,
            $this->checkInListDataService,
            $this->eventSettingsRepository,
            $this->db,
            $this->markOrderAsPaidService,
            $this->orderRepository,
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

    /**
     * The batch that used to stop the door for the rest of the night: two tickets on one unpaid
     * order, both scanned as "mark as paid", both in the same request. The first one settles the
     * order, and the second used to find it already settled and throw — which left the endpoint
     * answering 500, and the scanner resending the very same batch every thirty seconds forever.
     */
    public function testTwoTicketsOnTheSameOrderPayItOnceAndBothWalkIn(): void
    {
        $checkInList = $this->activeCheckInList();
        $first = $this->attendee('A-ONE', id: 10, productId: 2, orderId: 7, status: AttendeeStatus::AWAITING_PAYMENT->name);
        $second = $this->attendee('A-TWO', id: 11, productId: 2, orderId: 7, status: AttendeeStatus::AWAITING_PAYMENT->name);

        $this->primeFlow($checkInList, collect([$first, $second]), allowOfflinePaymentCheckIn: true);
        $this->checkInListDataService->shouldReceive('validateAttendeeBelongsToCheckInList')->twice()->andReturnNull();

        // The second read sees what the first attendee's transaction just committed.
        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->twice()
            ->andReturn(
                $this->orderWithStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name),
                $this->orderWithStatus(OrderStatus::COMPLETED->name),
            );

        $this->markOrderAsPaidService->shouldReceive('markOrderAsPaid')->once();
        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->twice()
            ->andReturn(m::mock(AttendeeCheckInDomainObject::class));

        $response = $this->service->checkInAttendees('cil_test', '127.0.0.1', collect([
            new AttendeeAndActionDTO('A-ONE', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
            new AttendeeAndActionDTO('A-TWO', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
        ]));

        $this->assertCount(2, $response->attendeeCheckIns);
        $this->assertSame([], $response->errors->errors);
    }

    public function testAnAlreadyPaidOrderStillLetsTheAttendeeIn(): void
    {
        $checkInList = $this->activeCheckInList();
        $attendee = $this->attendee('A-ONE', id: 10, productId: 2, orderId: 7, status: AttendeeStatus::AWAITING_PAYMENT->name);

        $this->primeFlow($checkInList, collect([$attendee]), allowOfflinePaymentCheckIn: true);
        $this->checkInListDataService->shouldReceive('validateAttendeeBelongsToCheckInList')->once()->andReturnNull();

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->orderWithStatus(OrderStatus::COMPLETED->name));

        // Paying it again is what throws, and the person is entitled to walk in either way.
        $this->markOrderAsPaidService->shouldNotReceive('markOrderAsPaid');
        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn(m::mock(AttendeeCheckInDomainObject::class));

        $response = $this->service->checkInAttendees('cil_test', '127.0.0.1', collect([
            new AttendeeAndActionDTO('A-ONE', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
        ]));

        $this->assertCount(1, $response->attendeeCheckIns);
        $this->assertSame([], $response->errors->errors);
    }

    public function testAnOrderInAnUnpayableStateRefusesOnlyItsOwnAttendee(): void
    {
        $checkInList = $this->activeCheckInList();
        $cancelled = $this->attendee('A-CANCELLED', id: 10, productId: 2, orderId: 7, status: AttendeeStatus::AWAITING_PAYMENT->name);
        $other = $this->attendee('A-OTHER', id: 11, productId: 2, orderId: 8);

        $this->primeFlow($checkInList, collect([$cancelled, $other]), allowOfflinePaymentCheckIn: true);
        $this->checkInListDataService->shouldReceive('validateAttendeeBelongsToCheckInList')->twice()->andReturnNull();

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->orderWithStatus(OrderStatus::CANCELLED->name));

        $this->markOrderAsPaidService->shouldNotReceive('markOrderAsPaid');
        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn(m::mock(AttendeeCheckInDomainObject::class));

        $response = $this->service->checkInAttendees('cil_test', '127.0.0.1', collect([
            new AttendeeAndActionDTO('A-CANCELLED', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
            new AttendeeAndActionDTO('A-OTHER', AttendeeCheckInActionType::CHECK_IN),
        ]));

        $this->assertCount(1, $response->attendeeCheckIns);
        $this->assertArrayHasKey('A-CANCELLED', $response->errors->errors);
        $this->assertArrayNotHasKey('A-OTHER', $response->errors->errors);
    }

    /**
     * The status check above is a read followed by a write, so two requests can get past it at the
     * same time and one of them still loses. That conflict must stay with its attendee: escaping it
     * renders as a 500, and the scanner reads 500 as worth retrying forever.
     */
    public function testAConflictFromTheOrderServiceStaysWithItsAttendee(): void
    {
        $checkInList = $this->activeCheckInList();
        $racing = $this->attendee('A-RACING', id: 10, productId: 2, orderId: 7, status: AttendeeStatus::AWAITING_PAYMENT->name);
        $other = $this->attendee('A-OTHER', id: 11, productId: 2, orderId: 8);

        $this->primeFlow($checkInList, collect([$racing, $other]), allowOfflinePaymentCheckIn: true);
        $this->checkInListDataService->shouldReceive('validateAttendeeBelongsToCheckInList')->twice()->andReturnNull();

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->orderWithStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name));

        $this->markOrderAsPaidService
            ->shouldReceive('markOrderAsPaid')
            ->once()
            ->andThrow(new ResourceConflictException('Order is not awaiting offline payment'));

        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->twice()
            ->andReturn(m::mock(AttendeeCheckInDomainObject::class));

        $response = $this->service->checkInAttendees('cil_test', '127.0.0.1', collect([
            new AttendeeAndActionDTO('A-RACING', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
            new AttendeeAndActionDTO('A-OTHER', AttendeeCheckInActionType::CHECK_IN),
        ]));

        $this->assertArrayHasKey('A-RACING', $response->errors->errors);
        $this->assertArrayNotHasKey('A-OTHER', $response->errors->errors);
        $this->assertCount(1, $response->attendeeCheckIns);
    }

    /**
     * This runs behind a public endpoint whose only secret is a shareable short id, so the order has
     * to be scoped to the event and never reachable by id alone.
     */
    public function testTheOrderIsLookedUpScopedToItsEvent(): void
    {
        $checkInList = $this->activeCheckInList();
        $attendee = $this->attendee('A-ONE', id: 10, productId: 2, orderId: 7, status: AttendeeStatus::AWAITING_PAYMENT->name);

        $this->primeFlow($checkInList, collect([$attendee]), allowOfflinePaymentCheckIn: true);
        $this->checkInListDataService->shouldReceive('validateAttendeeBelongsToCheckInList')->once()->andReturnNull();

        $this->orderRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                OrderDomainObjectAbstract::ID => 7,
                OrderDomainObjectAbstract::EVENT_ID => 123,
            ])
            ->andReturn($this->orderWithStatus(OrderStatus::COMPLETED->name));

        $this->attendeeCheckInRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn(m::mock(AttendeeCheckInDomainObject::class));

        $this->service->checkInAttendees('cil_test', '127.0.0.1', collect([
            new AttendeeAndActionDTO('A-ONE', AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID),
        ]));
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

    private function attendee(
        string $publicId,
        int    $id,
        int    $productId,
        int    $orderId = 1,
        string $status = AttendeeStatus::ACTIVE->name,
    ): AttendeeDomainObject
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getPublicId')->andReturn($publicId);
        $attendee->shouldReceive('getId')->andReturn($id);
        $attendee->shouldReceive('getProductId')->andReturn($productId);
        $attendee->shouldReceive('getOrderId')->andReturn($orderId);
        $attendee->shouldReceive('getEventId')->andReturn(123);
        $attendee->shouldReceive('getStatus')->andReturn($status);
        $attendee->shouldReceive('getFullName')->andReturn('Juan Perez');

        return $attendee;
    }

    private function orderWithStatus(string $status): OrderDomainObject
    {
        $order = m::mock(OrderDomainObject::class);
        $order->shouldReceive('getStatus')->andReturn($status);

        return $order;
    }

    /**
     * @param Collection<int, AttendeeDomainObject> $attendees what the public_id lookup resolves
     */
    private function primeFlow(
        CheckInListDomainObject $checkInList,
        Collection              $attendees,
        bool                    $allowOfflinePaymentCheckIn = false,
    ): void
    {
        $eventSettings = m::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getAllowOrdersAwaitingOfflinePaymentToCheckIn')
            ->andReturn($allowOfflinePaymentCheckIn);

        $this->checkInListDataService->shouldReceive('getCheckInList')->once()->andReturn($checkInList);
        $this->checkInListDataService->shouldReceive('getAttendees')->once()->andReturn($attendees);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->once()->andReturn($eventSettings);
        $this->attendeeCheckInRepository->shouldReceive('findWhereIn')->once()->andReturn(new Collection());

        $this->db->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
    }
}
