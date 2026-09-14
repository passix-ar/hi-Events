<?php

namespace Tests\Unit\Services\Domain\CheckInList;

use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\CheckInList\CheckInListProductAssociationService;
use HiEvents\Services\Domain\CheckInList\UpdateCheckInListService;
use HiEvents\Services\Domain\Product\EventProductValidationService;
use Illuminate\Database\DatabaseManager;
use Mockery as m;
use Tests\TestCase;

class UpdateCheckInListServiceTest extends TestCase
{
    public function test_a_list_from_another_event_is_rejected_before_detaching_its_products(): void
    {
        // Es el caso mas grave de infra#4: addCheckInListToProducts() desengancha
        // la lista de todos los productos que no vienen en el request. Con el id
        // de la lista de otro organizador, su escaner deja de reconocer las
        // entradas en la puerta.
        $database = m::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());

        $lists = m::mock(CheckInListRepositoryInterface::class);
        $lists->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $lists->shouldNotReceive('updateWhere');

        $validation = m::mock(EventProductValidationService::class);
        $validation->shouldNotReceive('validateProductIds');

        $association = m::mock(CheckInListProductAssociationService::class);
        $association->shouldNotReceive('addCheckInListToProducts');

        $events = m::mock(EventRepositoryInterface::class);

        $service = new UpdateCheckInListService($database, $validation, $association, $lists, $events);

        $this->expectException(ResourceNotFoundException::class);
        $service->updateCheckInList(
            (new CheckInListDomainObject)->setId(99)->setEventId(1),
            [10],
        );
    }
}
