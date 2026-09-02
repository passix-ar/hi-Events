<?php

namespace Tests\Unit\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Services\Application\Handlers\SeatingSection\ReorderSeatingSectionsHandler;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ReorderSeatingSectionsHandlerTest extends TestCase
{
    private SeatingSectionRepositoryInterface|MockInterface $repository;

    private ReorderSeatingSectionsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(SeatingSectionRepositoryInterface::class);
        $this->handler = new ReorderSeatingSectionsHandler($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sections_are_numbered_in_the_order_they_arrive(): void
    {
        $this->repository->shouldReceive('findWhere')
            ->andReturn(collect([$this->section(7), $this->section(8), $this->section(9)]));

        $this->repository->shouldReceive('updateFromArray')->once()->with(9, ['order' => 0]);
        $this->repository->shouldReceive('updateFromArray')->once()->with(7, ['order' => 1]);
        $this->repository->shouldReceive('updateFromArray')->once()->with(8, ['order' => 2]);

        $result = $this->handler->handle(1, [9, 7, 8]);

        $this->assertCount(3, $result);
    }

    public function test_ids_from_another_event_are_ignored(): void
    {
        $this->repository->shouldReceive('findWhere')
            ->andReturn(collect([$this->section(7), $this->section(8)]));

        $this->repository->shouldReceive('updateFromArray')->once()->with(8, ['order' => 0]);
        $this->repository->shouldReceive('updateFromArray')->once()->with(7, ['order' => 1]);
        $this->repository->shouldNotReceive('updateFromArray')->with(999, Mockery::any());

        $result = $this->handler->handle(1, [8, 999, 7]);

        $this->assertCount(2, $result);
    }

    private function section(int $id): SeatingSectionDomainObject
    {
        return (new SeatingSectionDomainObject)->setId($id);
    }
}
