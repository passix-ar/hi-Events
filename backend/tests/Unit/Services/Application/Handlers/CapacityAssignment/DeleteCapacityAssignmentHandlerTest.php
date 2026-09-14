<?php

namespace Tests\Unit\Services\Application\Handlers\CapacityAssignment;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\CapacityAssignmentRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\CapacityAssignment\DeleteCapacityAssignmentHandler;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class DeleteCapacityAssignmentHandlerTest extends TestCase
{
    public function test_an_assignment_from_another_event_is_rejected_before_touching_its_products(): void
    {
        // removeCapacityAssignmentFromProducts() no filtra por evento: si corre
        // con un id ajeno le saca el limite de capacidad a productos de otro
        // organizador. El chequeo de pertenencia tiene que cortar antes (infra#4).
        $assignments = m::mock(CapacityAssignmentRepositoryInterface::class);
        $assignments->shouldReceive('findFirstWhere')
            ->once()
            ->with(['id' => 99, 'event_id' => 1])
            ->andReturnNull();
        $assignments->shouldNotReceive('deleteWhere');

        $products = m::mock(ProductRepositoryInterface::class);
        $products->shouldNotReceive('removeCapacityAssignmentFromProducts');

        $database = m::mock(DatabaseManager::class);
        $database->shouldNotReceive('transaction');

        $handler = new DeleteCapacityAssignmentHandler($assignments, $products, $database);

        $this->expectException(ResourceNotFoundException::class);
        $handler->handle(99, 1);
    }
}
