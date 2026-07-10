<?php

namespace Tests\Unit\Services\Domain\Seating;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\Seating\Exception\SeatsUnavailableException;
use HiEvents\Services\Domain\Seating\SeatClaimService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SeatClaimServiceTest extends TestCase
{
    private SeatRepositoryInterface|MockInterface $seatRepository;
    private SeatingSectionRepositoryInterface|MockInterface $seatingSectionRepository;
    private SeatClaimService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seatRepository = Mockery::mock(SeatRepositoryInterface::class);
        $this->seatingSectionRepository = Mockery::mock(SeatingSectionRepositoryInterface::class);

        $this->service = new SeatClaimService(
            $this->seatRepository,
            $this->seatingSectionRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testNoSeatIdsIsANoOp(): void
    {
        $this->seatingSectionRepository->shouldNotReceive('findWhere');
        $this->seatRepository->shouldNotReceive('claimSeats');

        $this->service->claimSeatsForOrder(
            $this->makeOrder(),
            collect([$this->makeProductDetails(10, null)]),
        );

        $this->assertTrue(true);
    }

    public function testClaimsSeatsForTheProductsSections(): void
    {
        $this->seatingSectionRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([
                $this->makeSection(5, 10),
                $this->makeSection(6, 99),
            ]));

        $this->seatRepository->shouldReceive('claimSeats')
            ->once()
            ->withArgs(function (int $orderId, int $eventId, array $seatIds, array $sectionIds) {
                return $orderId === 1
                    && $eventId === 2
                    && $seatIds === [100, 101]
                    && $sectionIds === [5];
            })
            ->andReturn(2);

        $this->service->claimSeatsForOrder(
            $this->makeOrder(),
            collect([$this->makeProductDetails(10, [100, 101])]),
        );

        $this->assertTrue(true);
    }

    public function testThrowsWhenFewerSeatsAreClaimedThanRequested(): void
    {
        $this->seatingSectionRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect([$this->makeSection(5, 10)]));

        $this->seatRepository->shouldReceive('claimSeats')->once()->andReturn(1);

        $this->expectException(SeatsUnavailableException::class);

        $this->service->claimSeatsForOrder(
            $this->makeOrder(),
            collect([$this->makeProductDetails(10, [100, 101])]),
        );
    }

    public function testThrowsWhenProductHasNoActiveSections(): void
    {
        $this->seatingSectionRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn(collect());

        $this->seatRepository->shouldNotReceive('claimSeats');

        $this->expectException(SeatsUnavailableException::class);

        $this->service->claimSeatsForOrder(
            $this->makeOrder(),
            collect([$this->makeProductDetails(10, [100])]),
        );
    }

    private function makeOrder(): OrderDomainObject
    {
        return (new OrderDomainObject())
            ->setId(1)
            ->setEventId(2);
    }

    private function makeProductDetails(int $productId, ?array $seatIds): ProductOrderDetailsDTO
    {
        return new ProductOrderDetailsDTO(
            product_id: $productId,
            quantities: collect(),
            seat_ids: $seatIds,
        );
    }

    private function makeSection(int $id, int $productId): SeatingSectionDomainObject
    {
        return (new SeatingSectionDomainObject())
            ->setId($id)
            ->setProductId($productId);
    }
}
