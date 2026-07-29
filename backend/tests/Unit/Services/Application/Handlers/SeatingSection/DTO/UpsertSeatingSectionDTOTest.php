<?php

namespace Tests\Unit\Services\Application\Handlers\SeatingSection\DTO;

use HiEvents\Services\Application\Handlers\SeatingSection\DTO\UpsertSeatingSectionDTO;
use Tests\TestCase;

class UpsertSeatingSectionDTOTest extends TestCase
{
    public function test_it_hydrates_every_field_from_an_array(): void
    {
        $dto = UpsertSeatingSectionDTO::from([
            'id' => 7,
            'name' => 'Platea Baja',
            'event_id' => 2,
            'product_id' => 3,
            'row_count' => 5,
            'seats_per_row' => 10,
            'status' => 'ACTIVE',
            'disabled_seats' => ['A1', 'E10'],
        ]);

        $this->assertSame(7, $dto->id);
        $this->assertSame('Platea Baja', $dto->name);
        $this->assertSame(2, $dto->event_id);
        $this->assertSame(3, $dto->product_id);
        $this->assertSame(5, $dto->row_count);
        $this->assertSame(10, $dto->seats_per_row);
        $this->assertSame('ACTIVE', $dto->status);
        $this->assertSame(['A1', 'E10'], $dto->disabled_seats);
    }

    public function test_id_and_disabled_seats_default_to_null_when_creating(): void
    {
        $dto = UpsertSeatingSectionDTO::from([
            'name' => 'Palco',
            'event_id' => 2,
            'product_id' => 3,
            'row_count' => 1,
            'seats_per_row' => 4,
            'status' => 'INACTIVE',
            'disabled_seats' => null,
        ]);

        $this->assertNull($dto->id);
        $this->assertNull($dto->disabled_seats);
        $this->assertSame('INACTIVE', $dto->status);
    }
}
