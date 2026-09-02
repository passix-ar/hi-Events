<?php

namespace Tests\Unit\Services\Domain\Seating;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Seating\SeatOrderIntegrityService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeatOrderIntegrityServiceTest extends TestCase
{
    private SeatRepositoryInterface|MockInterface $seatRepository;

    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;

    private SeatOrderIntegrityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seatRepository = Mockery::mock(SeatRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);

        $this->service = new SeatOrderIntegrityService(
            $this->seatRepository,
            $this->attendeeRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_an_order_without_seats_is_not_inspected(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$this->makeAttendee(1, null)]));

        $this->seatRepository->shouldNotReceive('findByOrderId');

        $this->assertSame([], $this->service->findSeatsLostByOrder(10));
    }

    public function test_seats_still_held_by_the_order_are_not_reported(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([
                $this->makeAttendee(1, 'Platea baja - A1'),
                $this->makeAttendee(2, 'Platea baja - B1'),
            ]));

        $this->seatRepository->shouldReceive('findByOrderId')
            ->once()
            ->andReturn(collect([$this->makeSeat(1), $this->makeSeat(2)]));

        $this->assertSame([], $this->service->findSeatsLostByOrder(10));
    }

    public function test_a_seat_claimed_by_another_buyer_is_reported(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([
                $this->makeAttendee(1, 'Platea baja - A1'),
                $this->makeAttendee(2, 'Platea baja - B1'),
            ]));

        // B1 was re-claimed, so no seat row points at attendee 2 any more.
        $this->seatRepository->shouldReceive('findByOrderId')
            ->once()
            ->andReturn(collect([$this->makeSeat(1)]));

        $this->assertSame(['Platea baja - B1'], $this->service->findSeatsLostByOrder(10));
    }

    public function test_a_seat_whose_attendee_was_cleared_is_reported(): void
    {
        $this->attendeeRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$this->makeAttendee(1, 'Platea baja - A1')]));

        $this->seatRepository->shouldReceive('findByOrderId')
            ->once()
            ->andReturn(collect([$this->makeSeat(null)]));

        $this->assertSame(['Platea baja - A1'], $this->service->findSeatsLostByOrder(10));
    }

    private function makeAttendee(int $id, ?string $seatLabel): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)
            ->setId($id)
            ->setSeatLabel($seatLabel);
    }

    private function makeSeat(?int $attendeeId): SeatDomainObject
    {
        return (new SeatDomainObject)
            ->setId($attendeeId ?? 99)
            ->setAttendeeId($attendeeId);
    }
}
