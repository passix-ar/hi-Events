<?php

namespace Tests\Unit\Services\Domain\Seating;

use HiEvents\DomainObjects\Enums\SeatState;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Seating\CreateSeatingSectionService;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use HiEvents\Services\Domain\Seating\SeatGenerationService;
use HiEvents\Services\Domain\Seating\UpdateSeatingSectionService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class UpdateSeatingSectionServiceTest extends TestCase
{
    private DatabaseManager|MockInterface $databaseManager;

    private SeatingSectionRepositoryInterface|MockInterface $seatingSectionRepository;

    private SeatRepositoryInterface|MockInterface $seatRepository;

    private CreateSeatingSectionService|MockInterface $createSeatingSectionService;

    private UpdateSeatingSectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->seatingSectionRepository = Mockery::mock(SeatingSectionRepositoryInterface::class);
        $this->seatRepository = Mockery::mock(SeatRepositoryInterface::class);
        $this->createSeatingSectionService = Mockery::mock(CreateSeatingSectionService::class);

        $this->service = new UpdateSeatingSectionService(
            $this->databaseManager,
            $this->seatingSectionRepository,
            $this->seatRepository,
            $this->createSeatingSectionService,
            new SeatGenerationService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function existingSection(): SeatingSectionDomainObject
    {
        return (new SeatingSectionDomainObject)
            ->setId(5)
            ->setEventId(2)
            ->setProductId(10)
            ->setName('Balcony')
            ->setRowCount(2)
            ->setSeatsPerRow(2)
            ->setStatus(SeatingSectionStatus::ACTIVE->name);
    }

    private function requestedSection(): SeatingSectionDomainObject
    {
        return (new SeatingSectionDomainObject)
            ->setId(5)
            ->setEventId(2)
            ->setProductId(10)
            ->setName('Balcony')
            ->setRowCount(2)
            ->setSeatsPerRow(2)
            ->setStatus(SeatingSectionStatus::ACTIVE->name);
    }

    private function allSeats(string $stateForA1 = SeatState::AVAILABLE->name): \Illuminate\Support\Collection
    {
        $seats = collect();
        foreach ([['A', 1], ['A', 2], ['B', 1], ['B', 2]] as [$row, $number]) {
            $seats->push(
                (new SeatDomainObject)
                    ->setSeatingSectionId(5)
                    ->setRowLabel($row)
                    ->setSeatNumber($number)
                    ->setLabel($row.$number)
                    ->setState($row.$number === 'A1' ? $stateForA1 : SeatState::AVAILABLE->name)
            );
        }

        return $seats;
    }

    private function expectValidationsPass(): void
    {
        $this->createSeatingSectionService->shouldReceive('validateLayout')->once();
        $this->createSeatingSectionService->shouldReceive('validateProduct')->once();
        $this->createSeatingSectionService->shouldReceive('normaliseAislePositions')->andReturnNull();
        $this->createSeatingSectionService->shouldReceive('validateDisabledSeats')->once();
    }

    private function expectTransactionWithLock(): void
    {
        $this->databaseManager->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $this->databaseManager->shouldReceive('statement')
            ->once()
            ->with('SELECT pg_advisory_xact_lock(?)', [2]);
    }

    public function test_blocking_a_sold_seat_throws(): void
    {
        $this->seatingSectionRepository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->existingSection());

        $this->expectValidationsPass();
        $this->expectTransactionWithLock();

        $this->seatRepository->shouldReceive('findByEventIdWithState')
            ->once()
            ->with(2, [5])
            ->andReturn($this->allSeats(SeatState::SOLD->name));

        $this->expectException(SeatingSectionInUseException::class);

        $this->service->updateSeatingSection($this->requestedSection(), ['A1']);
    }

    public function test_null_disabled_seats_leaves_blocks_untouched(): void
    {
        $this->seatingSectionRepository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->existingSection());

        $this->expectValidationsPass();
        $this->expectTransactionWithLock();

        $this->seatRepository->shouldReceive('findByEventIdWithState')
            ->once()
            ->andReturn($this->allSeats());

        $this->seatRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn($this->allSeats());

        $this->seatRepository->shouldNotReceive('updateWhere');

        $this->seatingSectionRepository->shouldReceive('updateFromArray')
            ->once()
            ->andReturn($this->requestedSection());

        $result = $this->service->updateSeatingSection($this->requestedSection(), null);

        $this->assertSame(5, $result->getId());
    }

    public function test_empty_disabled_seats_clears_all_blocks(): void
    {
        $this->seatingSectionRepository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($this->existingSection());

        $this->expectValidationsPass();
        $this->expectTransactionWithLock();

        $this->seatRepository->shouldReceive('findByEventIdWithState')
            ->once()
            ->andReturn($this->allSeats());

        $this->seatRepository->shouldReceive('findWhere')
            ->once()
            ->andReturn($this->allSeats());

        $this->seatRepository->shouldReceive('updateWhere')
            ->once()
            ->withArgs(function (array $attributes, array $where) {
                return $attributes === ['is_disabled' => false]
                    && $where === ['seating_section_id' => 5];
            })
            ->andReturn(4);

        $this->seatingSectionRepository->shouldReceive('updateFromArray')
            ->once()
            ->andReturn($this->requestedSection());

        $result = $this->service->updateSeatingSection($this->requestedSection(), []);

        $this->assertSame(5, $result->getId());
    }
}
