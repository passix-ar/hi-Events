<?php

namespace Tests\Unit\Services\Domain\Seating;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Seating\SeatAttendeeAssignmentService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeatAttendeeAssignmentServiceTest extends TestCase
{
    private SeatRepositoryInterface|MockInterface $seatRepository;
    private SeatingSectionRepositoryInterface|MockInterface $seatingSectionRepository;
    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;
    private SeatAttendeeAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seatRepository = Mockery::mock(SeatRepositoryInterface::class);
        $this->seatingSectionRepository = Mockery::mock(SeatingSectionRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);

        $this->service = new SeatAttendeeAssignmentService(
            $this->seatRepository,
            $this->seatingSectionRepository,
            $this->attendeeRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testNoSeatsIsANoOp(): void
    {
        $this->seatRepository->shouldReceive('findByOrderId')->once()->andReturn(collect());
        $this->attendeeRepository->shouldNotReceive('findWhere');

        $this->service->assignSeatsForOrder($this->makeOrder());

        $this->assertTrue(true);
    }

    public function testAssignsSeatsToAttendeesPositionallyPerProduct(): void
    {
        $section = (new SeatingSectionDomainObject())
            ->setId(5)
            ->setProductId(10)
            ->setName('Balcony');

        $seatA1 = $this->makeSeat(100, 5, 'A1');
        $seatA2 = $this->makeSeat(101, 5, 'A2');

        $this->seatRepository->shouldReceive('findByOrderId')
            ->once()
            ->andReturn(collect([$seatA1, $seatA2]));

        $this->seatingSectionRepository->shouldReceive('findWhereIn')
            ->once()
            ->andReturn(collect([$section]));

        $gaAttendee = $this->makeAttendee(1, 99);
        $seatedAttendeeOne = $this->makeAttendee(2, 10);
        $seatedAttendeeTwo = $this->makeAttendee(3, 10);

        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$gaAttendee, $seatedAttendeeOne, $seatedAttendeeTwo]));

        $this->attendeeRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(2, ['seat_label' => 'Balcony - A1'])
            ->andReturn($seatedAttendeeOne);
        $this->attendeeRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(3, ['seat_label' => 'Balcony - A2'])
            ->andReturn($seatedAttendeeTwo);

        $this->seatRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(100, ['attendee_id' => 2])
            ->andReturn($seatA1);
        $this->seatRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(101, ['attendee_id' => 3])
            ->andReturn($seatA2);

        $this->service->assignSeatsForOrder($this->makeOrder());

        $this->assertTrue(true);
    }

    public function testThrowsWhenSeatsOutnumberAttendees(): void
    {
        $section = (new SeatingSectionDomainObject())
            ->setId(5)
            ->setProductId(10)
            ->setName('Balcony');

        $this->seatRepository->shouldReceive('findByOrderId')
            ->once()
            ->andReturn(collect([
                $this->makeSeat(100, 5, 'A1'),
                $this->makeSeat(101, 5, 'A2'),
            ]));

        $this->seatingSectionRepository->shouldReceive('findWhereIn')
            ->once()
            ->andReturn(collect([$section]));

        $attendee = $this->makeAttendee(2, 10);
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$attendee]));

        $this->attendeeRepository->shouldReceive('updateFromArray')->once()->andReturn($attendee);
        $this->seatRepository->shouldReceive('updateFromArray')->once()->andReturn($this->makeSeat(100, 5, 'A1'));

        $this->expectException(ResourceConflictException::class);

        $this->service->assignSeatsForOrder($this->makeOrder());
    }

    private function makeOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())->setId(1)->setEventId(2);
    }

    private function makeSeat(int $id, int $sectionId, string $label): SeatDomainObject
    {
        return (new SeatDomainObject())
            ->setId($id)
            ->setSeatingSectionId($sectionId)
            ->setLabel($label);
    }

    private function makeAttendee(int $id, int $productId): AttendeeDomainObject
    {
        return (new AttendeeDomainObject())
            ->setId($id)
            ->setProductId($productId);
    }
}
