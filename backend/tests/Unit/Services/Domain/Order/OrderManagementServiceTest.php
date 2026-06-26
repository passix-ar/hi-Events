<?php

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\MercadopagoPreferenceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Repository\Interfaces\MercadopagoPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Tax\TaxAndFeeOrderRollupService;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class OrderManagementServiceTest extends TestCase
{
    private OrderManagementService $service;

    private MockInterface|OrderRepositoryInterface $orderRepository;

    private MockInterface|MercadopagoPreferenceRepositoryInterface $preferenceRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->preferenceRepository = Mockery::mock(MercadopagoPreferenceRepositoryInterface::class);

        $this->service = new OrderManagementService(
            orderRepository: $this->orderRepository,
            taxAndFeeOrderRollupService: Mockery::mock(TaxAndFeeOrderRollupService::class),
            mercadopagoPreferenceRepository: $this->preferenceRepository,
        );
    }

    public function test_it_deletes_reserved_orders_without_a_payment_in_progress(): void
    {
        $this->orderRepository->shouldReceive('findWhere')->once()->andReturn(new Collection([
            (new OrderDomainObject)->setId(10),
            (new OrderDomainObject)->setId(11),
            (new OrderDomainObject)->setId(12),
        ]));

        // Order 11 has a MercadoPago preference => payment in progress => must be preserved.
        $this->preferenceRepository->shouldReceive('findWhere')->once()->andReturn(new Collection([
            (new MercadopagoPreferenceDomainObject)->setOrderId(11),
        ]));

        $this->orderRepository
            ->shouldReceive('deleteWhere')
            ->once()
            ->with(Mockery::on(function (array $conditions) {
                [$field, $operator, $ids] = $conditions[0];

                return $field === 'id'
                    && $operator === 'in'
                    && $ids === [10, 12];
            }))
            ->andReturn(2);

        $this->service->deleteExistingOrders(eventId: 1, sessionId: 'session-abc');
    }

    public function test_it_does_nothing_when_there_are_no_reserved_orders(): void
    {
        $this->orderRepository->shouldReceive('findWhere')->once()->andReturn(new Collection);

        $this->preferenceRepository->shouldNotReceive('findWhere');
        $this->orderRepository->shouldNotReceive('deleteWhere');

        $this->service->deleteExistingOrders(eventId: 1, sessionId: 'session-abc');
    }

    public function test_it_does_not_delete_when_every_order_has_a_payment_in_progress(): void
    {
        $this->orderRepository->shouldReceive('findWhere')->once()->andReturn(new Collection([
            (new OrderDomainObject)->setId(10),
            (new OrderDomainObject)->setId(11),
        ]));

        $this->preferenceRepository->shouldReceive('findWhere')->once()->andReturn(new Collection([
            (new MercadopagoPreferenceDomainObject)->setOrderId(10),
            (new MercadopagoPreferenceDomainObject)->setOrderId(11),
        ]));

        $this->orderRepository->shouldNotReceive('deleteWhere');

        $this->service->deleteExistingOrders(eventId: 1, sessionId: 'session-abc');
    }
}
