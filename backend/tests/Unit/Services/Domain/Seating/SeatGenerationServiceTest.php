<?php

namespace Tests\Unit\Services\Domain\Seating;

use HiEvents\Services\Domain\Seating\SeatGenerationService;
use Tests\TestCase;

class SeatGenerationServiceTest extends TestCase
{
    private SeatGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SeatGenerationService();
    }

    public function testRowLabelsFollowSpreadsheetStyle(): void
    {
        $this->assertSame('A', $this->service->labelForRowIndex(0));
        $this->assertSame('B', $this->service->labelForRowIndex(1));
        $this->assertSame('Z', $this->service->labelForRowIndex(25));
        $this->assertSame('AA', $this->service->labelForRowIndex(26));
        $this->assertSame('AB', $this->service->labelForRowIndex(27));
        $this->assertSame('AZ', $this->service->labelForRowIndex(51));
        $this->assertSame('BA', $this->service->labelForRowIndex(52));
    }

    public function testGenerateGridProducesEverySeatWithLabels(): void
    {
        $grid = $this->service->generateGrid(3, 4);

        $this->assertCount(12, $grid);
        $this->assertSame(['row_label' => 'A', 'seat_number' => 1, 'label' => 'A1'], $grid[0]);
        $this->assertSame(['row_label' => 'A', 'seat_number' => 4, 'label' => 'A4'], $grid[3]);
        $this->assertSame(['row_label' => 'C', 'seat_number' => 4, 'label' => 'C4'], $grid[11]);
    }

    public function testGridIsOrderedByRowThenSeatNumber(): void
    {
        $grid = $this->service->generateGrid(2, 2);

        $this->assertSame(['A1', 'A2', 'B1', 'B2'], array_column($grid, 'label'));
    }
}
