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

    public function test_each_section_keeps_the_spot_and_order_it_was_dropped_in(): void
    {
        $this->repository->shouldReceive('findWhere')
            ->andReturn(collect([$this->section(7), $this->section(8), $this->section(9)]));

        $this->repository->shouldReceive('updateFromArray')->once()->with(9, ['order' => 0, 'layout_position' => 'BEHIND']);
        $this->repository->shouldReceive('updateFromArray')->once()->with(7, ['order' => 1, 'layout_position' => 'LEFT']);
        $this->repository->shouldReceive('updateFromArray')->once()->with(8, ['order' => 2, 'layout_position' => 'LEFT']);

        $result = $this->handler->handle(1, [
            ['id' => 9, 'layout_position' => 'BEHIND'],
            ['id' => 7, 'layout_position' => 'LEFT'],
            ['id' => 8, 'layout_position' => 'LEFT'],
        ]);

        $this->assertCount(3, $result);
    }

    public function test_sections_from_another_event_are_ignored(): void
    {
        $this->repository->shouldReceive('findWhere')
            ->andReturn(collect([$this->section(7)]));

        $this->repository->shouldReceive('updateFromArray')->once()->with(7, ['order' => 0, 'layout_position' => 'CENTER']);
        $this->repository->shouldNotReceive('updateFromArray')->with(999, Mockery::any());

        $result = $this->handler->handle(1, [
            ['id' => 7, 'layout_position' => 'CENTER'],
            ['id' => 999, 'layout_position' => 'RIGHT'],
        ]);

        $this->assertCount(1, $result);
    }

    private function section(int $id): SeatingSectionDomainObject
    {
        return (new SeatingSectionDomainObject)->setId($id);
    }
}
