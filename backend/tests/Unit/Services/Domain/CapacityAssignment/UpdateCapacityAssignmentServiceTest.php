<?php

namespace Tests\Unit\Services\Domain\CapacityAssignment;

use HiEvents\DomainObjects\CapacityAssignmentDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\CapacityAssignmentRepositoryInterface;
use HiEvents\Services\Domain\CapacityAssignment\CapacityAssignmentProductAssociationService;
use HiEvents\Services\Domain\CapacityAssignment\UpdateCapacityAssignmentService;
use HiEvents\Services\Domain\Product\EventProductValidationService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class UpdateCapacityAssignmentServiceTest extends TestCase
{
    public function test_an_assignment_from_another_event_is_rejected_before_reassigning_products(): void
    {
        // addCapacityToProducts() borra las asociaciones previas de la
        // asignacion: con un id ajeno desengancharia productos de otro evento.
        $assignments = m::mock(CapacityAssignmentRepositoryInterface::class);
        $assignments->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $assignments->shouldNotReceive('updateWhere');

        $validation = m::mock(EventProductValidationService::class);
        $validation->shouldNotReceive('validateProductIds');

        $association = m::mock(CapacityAssignmentProductAssociationService::class);
        $association->shouldNotReceive('addCapacityToProducts');

        $database = m::mock(DatabaseManager::class);
        $database->shouldNotReceive('transaction');

        $service = new UpdateCapacityAssignmentService($database, $assignments, $validation, $association);

        $this->expectException(ResourceNotFoundException::class);
        $service->updateCapacityAssignment(
            (new CapacityAssignmentDomainObject)->setId(99)->setEventId(1),
            [10],
        );
    }
}
