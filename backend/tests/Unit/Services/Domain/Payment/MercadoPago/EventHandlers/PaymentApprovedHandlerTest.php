<?php

namespace Tests\Unit\Services\Domain\Payment\MercadoPago\EventHandlers;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentApprovedHandler;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class PaymentApprovedHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private Cache|MockInterface $cache;
    private LoggerInterface|MockInterface $logger;
    private PaymentApprovedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->cache = Mockery::mock(Cache::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(static fn ($callback) => $callback());

        $this->handler = new PaymentApprovedHandler(
            $this->orderRepository,
            Mockery::mock(MercadopagoPreferenceRepositoryInterface::class),
            Mockery::mock(MercadopagoPaymentRepositoryInterface::class),
            Mockery::mock(AttendeeRepositoryInterface::class),
            Mockery::mock(ProductQuantityUpdateService::class),
            Mockery::mock(OrderApplicationFeeService::class),
            Mockery::mock(EventSettingsRepositoryInterface::class),
            Mockery::mock(DomainEventDispatcherService::class),
            $databaseManager,
            $this->cache,
            $this->logger,
        );
    }

    private function mockAwaitingOrder(float $totalGross, string $currency): OrderDomainObject|MockInterface
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(55);
        $order->shouldReceive('getPaymentStatus')->andReturn(OrderPaymentStatus::AWAITING_PAYMENT->name);
        $order->shouldReceive('getTotalGross')->andReturn($totalGross);
        $order->shouldReceive('getCurrency')->andReturn($currency);

        $this->orderRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->with(['short_id' => 'ORDER-REF'])
            ->andReturn($order);

        return $order;
    }

    public function test_underpaid_amount_does_not_complete_order(): void
    {
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->mockAwaitingOrder(100.00, 'ARS');

        $this->orderRepository->shouldNotReceive('updateFromArray');
        $this->logger->shouldReceive('error')->once();

        $this->handler->handle([
            'id'                 => 123,
            'status'             => 'approved',
            'external_reference' => 'ORDER-REF',
            'transaction_amount' => 50.00,
            'currency_id'        => 'ARS',
        ]);
    }

    public function test_mismatched_currency_does_not_complete_order(): void
    {
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->mockAwaitingOrder(100.00, 'ARS');

        $this->orderRepository->shouldNotReceive('updateFromArray');
        $this->logger->shouldReceive('error')->once();

        $this->handler->handle([
            'id'                 => 123,
            'status'             => 'approved',
            'external_reference' => 'ORDER-REF',
            'transaction_amount' => 100.00,
            'currency_id'        => 'USD',
        ]);
    }
}
