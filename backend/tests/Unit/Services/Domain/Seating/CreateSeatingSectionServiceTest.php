<?php

namespace Tests\Unit\Services\Domain\Seating;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\CreateSeatingSectionService;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;
use HiEvents\Services\Domain\Seating\SeatGenerationService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateSeatingSectionServiceTest extends TestCase
{
    private DatabaseManager|MockInterface $databaseManager;

    private SeatingSectionRepositoryInterface|MockInterface $seatingSectionRepository;

    private SeatRepositoryInterface|MockInterface $seatRepository;

    private ProductRepositoryInterface|MockInterface $productRepository;

    private CreateSeatingSectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->seatingSectionRepository = Mockery::mock(SeatingSectionRepositoryInterface::class);
        $this->seatRepository = Mockery::mock(SeatRepositoryInterface::class);
        $this->productRepository = Mockery::mock(ProductRepositoryInterface::class);

        $this->service = new CreateSeatingSectionService(
            $this->databaseManager,
            $this->seatingSectionRepository,
            $this->seatRepository,
            $this->productRepository,
            new SeatGenerationService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_rejects_layouts_exceeding_the_seat_cap(): void
    {
        $this->expectException(InvalidSeatingLayoutException::class);

        $this->service->validateLayout(50, 50);
    }

    public function test_rejects_zero_or_oversized_dimensions(): void
    {
        $this->expectException(InvalidSeatingLayoutException::class);

        $this->service->validateLayout(0, 10);
    }

    public function test_rejects_products_from_another_event(): void
    {
        $this->productRepository->shouldReceive('findFirstWhere')->once()->andReturnNull();

        $this->expectException(UnrecognizedProductIdException::class);

        $this->service->validateProduct(10, 2);
    }

    public function test_rejects_non_ticket_products(): void
    {
        $this->productRepository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn((new ProductDomainObject)->setProductType(ProductType::GENERAL->name));

        $this->expectException(UnrecognizedProductIdException::class);

        $this->service->validateProduct(10, 2);
    }

    public function test_creates_section_and_bulk_inserts_seats(): void
    {
        $this->productRepository->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn((new ProductDomainObject)->setProductType(ProductType::TICKET->name));

        $this->databaseManager->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());

        $created = (new SeatingSectionDomainObject)
            ->setId(5)
            ->setEventId(2)
            ->setProductId(10)
            ->setRowCount(3)
            ->setSeatsPerRow(4);

        $this->seatingSectionRepository->shouldReceive('create')->once()->andReturn($created);

        $this->seatRepository->shouldReceive('insert')
            ->once()
            ->withArgs(function (array $inserts) {
                return count($inserts) === 12
                    && $inserts[0]['row_label'] === 'A'
                    && $inserts[0]['seating_section_id'] === 5
                    && $inserts[0]['event_id'] === 2
                    && $inserts[11]['label'] === 'C4';
            })
            ->andReturn(true);

        $section = (new SeatingSectionDomainObject)
            ->setEventId(2)
            ->setProductId(10)
            ->setName('Balcony')
            ->setRowCount(3)
            ->setSeatsPerRow(4)
            ->setStatus(SeatingSectionStatus::ACTIVE->name);

        $result = $this->service->createSeatingSection($section);

        $this->assertSame(5, $result->getId());
    }
}
